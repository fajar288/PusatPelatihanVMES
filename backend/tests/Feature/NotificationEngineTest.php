<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\RelationManagers\VersionsRelationManager;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\CompletionService;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\NotificationCenter;
use App\Services\NotificationDispatcher;
use App\Services\NotificationEvents;
use App\Services\OrganizationService;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use App\Support\Notify\Recipient;
use App\Support\Push\PushSender;
use App\Support\WhatsApp\WhatsAppSender;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fakes\FakePushSender;
use Tests\Fakes\FakeWhatsAppSender;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5D — Notification Engine (PRD Roadmap 5 §6, §6.1, §15 "WhatsApp and email can be triggered by the same
 * business event", "Notification delivery status and retry are logged"): katalog event, penerima per scope (perusahaan hanya
 * untuk enrollment bersponsor), jadwal, retry, status Queued/Sent/Delivered/Read/Failed, template berversi, kanal WA driver log.
 */
class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $ccna;

    private FakeWhatsAppSender $wa;

    private Organization $abc;

    private Organization $xyz;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 08:00:00'); // CCNA-2611 mulai 17 Nov
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        $this->ccna = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        $this->app->instance(PushSender::class, new FakePushSender);
        $this->wa = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSender::class, $this->wa);
        $orgs = app(OrganizationService::class);
        $this->abc = $orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->xyz = $orgs->create(['legal_name' => 'PT XYZ Energi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $name, string $email, ?string $phone = '0812-3456-7890'): Member
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'email_verified_at' => now(), 'role' => User::ROLE_STUDENT]);

        return app(MemberService::class)->createForUser($user, ['phone' => $phone])->load('user');
    }

    private function sponsored(Member $m, Organization $org, ?OrganizationUnit $unit = null, ?TrainingSchedule $s = null): Enrollment
    {
        return Enrollment::query()->create(['schedule_id' => ($s ?? $this->ccna)->id, 'member_id' => $m->id, 'type' => Enrollment::TYPE_CORPORATE,
            'sponsor_organization_id' => $org->id, 'sponsor_unit_id' => $unit?->id, 'participant_name' => $m->user->name,
            'participant_email' => $m->user->email, 'enrolled_at' => now()]);
    }

    private function selfFunded(Member $m): Enrollment
    {
        return Enrollment::query()->create(['schedule_id' => $this->ccna->id, 'member_id' => $m->id, 'participant_name' => $m->user->name,
            'participant_email' => $m->user->email, 'enrolled_at' => now()]);
    }

    private function corporateUser(Organization $org, string $role, ?OrganizationUnit $unit = null, string $email = ''): User
    {
        $u = User::factory()->create(['role' => User::ROLE_STUDENT] + ($email ? ['email' => $email] : []));
        app(OrganizationService::class)->addCorporateUser($org, $u, $role, $unit);

        return $u;
    }

    /** @return Collection<int, NotificationDelivery> */
    private function deliveries(string $key, ?string $audience = null)
    {
        return NotificationDelivery::query()->whereHas('event', fn ($q) => $q->where('key', $key))
            ->when($audience, fn ($q) => $q->where('audience', $audience))->orderBy('id')->get();
    }

    private function admin(string $role = User::ROLE_ADMINISTRATOR, string $name = 'Admin'): User
    {
        return User::factory()->create(['role' => $role, 'name' => $name]);
    }

    // ------------------------------------------------------------------ §15: satu event → WA + email + aplikasi

    public function test_payment_success_triggers_whatsapp_email_and_in_app_from_one_business_event(): void
    {
        $verifier = $this->admin(User::ROLE_FINANCE, 'Fina Verifikator');
        $otherFinance = $this->admin(User::ROLE_FINANCE, 'Fani Finance');
        $rina = $this->member('Rina', 'rina@example.com');
        $svc = app(RegistrationService::class);
        $reg = $svc->create(['type' => 'individual', 'name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '0812-3456-7890', 'consent' => true], $this->ccna, $rina->user);
        $reg = $svc->transition($reg->refresh(), RegistrationStatus::WaitingPayment);
        /** @var Invoice $invoice */
        $invoice = $reg->invoices()->firstOrFail();

        app(PaymentService::class)->recordReceived($invoice, $verifier, ['amount' => (float) $invoice->total]);

        $event = NotificationEvent::query()->where('key', 'payment.success')->sole();
        $this->assertSame('payment.success:'.$invoice->id, $event->dedupe_key);
        $this->assertSame($verifier->id, $event->actor_id, 'audit: pemicu tercatat');
        $p = $this->deliveries('payment.success', 'participant')->keyBy('channel');
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $p->keys()->all());
        $this->assertSame('delivered', $p['database']->status);
        $this->assertSame('sent', $p['mail']->status);
        $this->assertSame('sent', $p['whatsapp']->status);
        $this->assertSame('6281234567890', $p['whatsapp']->recipient);
        $this->assertSame('fake', $p['whatsapp']->provider);
        $this->assertSame(1, $p['whatsapp']->template_version);
        $wa = collect($this->wa->sent)->firstWhere('id', $p['whatsapp']->provider_message_id);
        $this->assertSame('6281234567890', $wa['to']);
        $this->assertStringContainsString($invoice->invoice_number, $wa['text']);
        $this->assertStringContainsString('/portal/pembayaran/'.$invoice->invoice_number, $wa['text']);
        $this->assertDatabaseHas('notification_logs', ['delivery_id' => $p['whatsapp']->id, 'attempt' => 1, 'status' => 'sent', 'channel' => 'whatsapp']);

        // admin VMES: "Dashboard" = lonceng panel (format Filament); verifikator sendiri tidak diberi tahu
        $adminRows = $this->deliveries('payment.success', 'admin');
        $this->assertSame([$otherFinance->id], $adminRows->pluck('user_id')->unique()->values()->all());
        $bell = $otherFinance->notifications()->sole();
        $this->assertSame('filament', $bell->data['format']);
        $this->assertSame('Invoice '.$invoice->invoice_number.' lunas', $bell->data['title']);

        // verifikasi ganda / event yang sama tidak membuat notifikasi ganda
        $tx = $invoice->transactions()->sole();
        app(PaymentService::class)->verify($tx, $verifier);
        app(NotificationEvents::class)->paymentSucceeded($invoice->refresh(), $verifier);
        $this->assertSame(1, NotificationEvent::query()->where('key', 'payment.success')->count());
        $this->assertSame($event->deliveries_count, NotificationDelivery::query()->where('event_id', $event->id)->count());

        // WhatsApp mati (konfigurasi) → event berikutnya tanpa kanal WA, kanal lain tetap jalan
        $this->wa->on = false;
        $svc->transition($reg->refresh(), RegistrationStatus::Cancelled, $verifier, 'Uji');
        $last = NotificationEvent::query()->where('key', 'registration.status_changed')->latest('id')->first()->deliveries;
        $this->assertSame([], $last->where('channel', 'whatsapp')->all());
        $this->assertNotEmpty($last->where('channel', 'database')->all());
    }

    // ------------------------------------------------------------------ scope perusahaan hanya untuk enrollment bersponsor

    public function test_corporate_recipients_only_for_sponsored_enrollments_within_scope(): void
    {
        $unitA = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Produksi']);
        $unitB = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Maintenance']);
        $hr = $this->corporateUser($this->abc, 'hr_admin', null, 'hr@abc.test');
        $finance = $this->corporateUser($this->abc, 'finance');
        $supervisorB = $this->corporateUser($this->abc, 'supervisor', $unitB);
        $hrXyz = $this->corporateUser($this->xyz, 'hr_admin');

        $budi = $this->member('Budi', 'budi@example.com');
        $sari = $this->member('Sari', 'sari@example.com', '0813-1111-2222');
        $budiEnr = $this->sponsored($budi, $this->abc, $unitA);
        $sariEnr = $this->selfFunded($sari);

        app(NotificationEvents::class)->classReminders(Carbon::parse('2026-11-17'));

        $corp = $this->deliveries('class.reminder', 'corporate');
        $this->assertSame([$hr->id], $corp->pluck('user_id')->unique()->values()->all(), 'finance/supervisor unit lain/organisasi lain tidak menerima');
        $this->assertEqualsCanonicalizing(['database', 'mail'], $corp->pluck('channel')->all());
        $mail = $corp->firstWhere('channel', 'mail');
        $this->assertSame('hr@abc.test', $mail->recipient);
        $this->assertSame($this->abc->id, $mail->organization_id);
        $this->assertStringContainsString('1 peserta PT ABC Manufaktur', $mail->content['body']);
        $this->assertStringContainsString('Budi', $mail->content['body']);
        $this->assertStringNotContainsString('Sari', $mail->content['body'], 'self-funded tidak pernah bocor ke perusahaan');
        $this->assertSame(0, NotificationDelivery::query()->whereIn('user_id', [$finance->id, $supervisorB->id, $hrXyz->id])->count());
        // peserta tetap menerima (keduanya)
        $this->assertEqualsCanonicalizing([$budi->user_id, $sari->user_id], $this->deliveries('class.reminder', 'participant')->pluck('user_id')->unique()->values()->all());

        // pelatihan selesai: self-funded → tanpa audiens perusahaan; sponsored → HR
        $coordinator = $this->admin(User::ROLE_TRAINING_COORDINATOR, 'Koordinator');
        $actor = $this->admin();
        app(CompletionService::class)->complete($sariEnr, $actor, true, 88);
        $this->assertSame([], $this->deliveries('training.completed', 'corporate')->all());
        app(CompletionService::class)->complete($budiEnr, $actor, true, 91);
        $done = $this->deliveries('training.completed', 'corporate');
        $this->assertSame([$hr->id], $done->pluck('user_id')->unique()->values()->all());
        $this->assertSame('Budi menyelesaikan Cisco CCNA', $done->first()->content['title']);
        $this->assertStringStartsWith('/corporate/'.$this->abc->code.'/peserta/', $done->first()->content['url']);
        // peserta: WA + email + aplikasi; admin (izin certificates.manage, bukan aktor) di lonceng panel
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $this->deliveries('training.completed', 'participant')->where('user_id', $budi->user_id)->pluck('channel')->all());
        $this->assertContains($coordinator->id, $this->deliveries('training.completed', 'admin')->pluck('user_id')->all());
        $this->assertNotContains($actor->id, $this->deliveries('training.completed', 'admin')->pluck('user_id')->all());

        // kebijakan exit "none": peserta yang sudah resign tidak lagi dilaporkan ke perusahaan
        $dewi = $this->member('Dewi', 'dewi@example.com');
        $emp = app(EmploymentService::class)->start($dewi, $this->abc);
        $dewiEnr = $this->sponsored($dewi, $this->abc);
        app(EmploymentService::class)->end($emp);
        $this->abc->update(['settings' => ['exit_access' => 'none']]);
        app(CompletionService::class)->complete($dewiEnr, $actor, true, 80);
        $this->assertSame(0, $this->deliveries('training.completed', 'corporate')->filter(fn ($d) => str_contains($d->content['title'], 'Dewi'))->count());
    }

    // ------------------------------------------------------------------ retry policy & log percobaan

    public function test_retry_policy_backs_off_logs_each_attempt_and_marks_failed(): void
    {
        $rina = $this->member('Rina', 'rina@example.com');
        $this->wa->failures = 99;
        app(NotificationCenter::class)->publish('class.reminder', [Recipient::user($rina->user)], ['program' => 'Cisco CCNA', 'class' => 'CCNA-2611']);

        $d = $this->deliveries('class.reminder')->firstWhere('channel', 'whatsapp');
        $this->assertSame('queued', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertEquals(now()->addMinute(), $d->next_attempt_at);
        $this->assertSame('Penyedia WA tidak merespons (simulasi)', $d->last_error);
        $this->assertSame('delivered', $this->deliveries('class.reminder')->firstWhere('channel', 'database')->status, 'kanal lain tidak ikut gagal');

        $this->artisan('notifications:dispatch')->expectsOutputToContain('Pengiriman diproses: 0')->assertSuccessful(); // belum jatuh tempo
        foreach ([1, 5, 30] as $minutes) {
            Carbon::setTestNow(now()->addMinutes($minutes));
            $this->artisan('notifications:dispatch')->assertSuccessful();
        }
        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame(4, $d->attempts);
        $this->assertNotNull($d->failed_at);
        $this->assertSame([1, 2, 3, 4], NotificationLog::query()->where('delivery_id', $d->id)->where('status', 'failed')->orderBy('attempt')->pluck('attempt')->all());

        // kirim ulang manual (diaudit) setelah penyedia pulih
        $this->wa->failures = 0;
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(ListNotificationDeliveries::class)
            ->callTableAction('retry', $d)->assertHasNoTableActionErrors();
        $d->refresh();
        $this->assertSame('sent', $d->status);
        $this->assertSame(5, $d->attempts);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.retried', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('notification_logs', ['delivery_id' => $d->id, 'attempt' => 5, 'status' => 'sent']);
    }

    // ------------------------------------------------------------------ jadwal (scheduled) & batal

    public function test_scheduled_announcement_is_sent_when_due_and_can_be_cancelled(): void
    {
        $rina = $this->member('Rina', 'rina@example.com');
        $this->selfFunded($rina);
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(ListNotificationTemplates::class)
            ->callAction('announce', data: ['target' => 'class', 'schedule_id' => $this->ccna->id, 'title' => 'Ruang pindah', 'message' => 'Kelas pindah ke lab 3.',
                'url' => '/portal/pelatihan', 'scheduled_at' => now()->addHours(2)->format('Y-m-d H:i:s')])
            ->assertHasNoActionErrors();
        $event = NotificationEvent::query()->where('key', 'announcement')->sole();
        $this->assertEquals(now()->addHours(2), $event->scheduled_at);
        $this->assertTrue($event->deliveries->every(fn ($d) => $d->status === 'queued' && $d->attempts === 0));
        $this->assertSame(0, $rina->user->notifications()->count());

        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame(0, $rina->user->notifications()->count(), 'belum waktunya');

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame('Ruang pindah', $rina->user->notifications()->sole()->data['title']);
        $this->assertSame('delivered', $event->deliveries()->where('channel', 'database')->sole()->status);

        // jadwal lain dibatalkan sebelum terkirim
        app(NotificationCenter::class)->send($rina->user, 'announcement', ['title' => 'Batal', 'message' => 'x'], null, null, null, now()->addDay());
        $queued = NotificationDelivery::query()->where('status', 'queued')->get();
        $this->assertNotEmpty($queued);
        $queued->each(fn ($d) => app(NotificationDispatcher::class)->cancel($d, $admin));
        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame(1, $rina->user->notifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.cancelled']);
    }

    // ------------------------------------------------------------------ status Delivered → Read & tanda terima penyedia

    public function test_read_status_from_portal_admin_bell_and_provider_receipts(): void
    {
        $rina = $this->member('Rina', 'rina@example.com');
        $staff = $this->admin(User::ROLE_TRAINING_COORDINATOR, 'Koordinator');
        app(RegistrationService::class)->create(['type' => 'individual', 'name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '0812-3456-7890', 'consent' => true], $this->ccna, $rina->user);

        $inApp = $this->deliveries('registration.received', 'participant')->firstWhere('channel', 'database');
        $this->assertSame('delivered', $inApp->status);
        Sanctum::actingAs($rina->user);
        $this->postJson('/api/v1/me/notifications/'.$inApp->notification_id.'/read')->assertOk();
        $this->assertSame('read', $inApp->refresh()->status);
        $this->assertNotNull($inApp->read_at);

        // lonceng panel admin (Filament) menandai dibaca langsung di tabel notifications → disinkronkan dispatcher
        $bell = $this->deliveries('registration.received', 'admin')->firstWhere('user_id', $staff->id);
        $this->assertSame('delivered', $bell->status);
        $staff->notifications()->update(['read_at' => now()]);
        $this->artisan('notifications:dispatch')->expectsOutputToContain('Ditandai dibaca: 1')->assertSuccessful();
        $this->assertSame('read', $bell->refresh()->status);

        // tanda terima penyedia WA: status hanya maju
        $wa = $this->deliveries('registration.received', 'participant')->firstWhere('channel', 'whatsapp');
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->receipt('fake', $wa->provider_message_id, 'delivered');
        $this->assertSame('delivered', $wa->refresh()->status);
        $dispatcher->receipt('fake', $wa->provider_message_id, 'read');
        $dispatcher->receipt('fake', $wa->provider_message_id, 'delivered');
        $this->assertSame('read', $wa->refresh()->status);
        $this->assertNotNull($wa->delivered_at);
        $this->assertNull($dispatcher->receipt('fake', 'tidak-ada', 'read'));
    }

    // ------------------------------------------------------------------ template berversi

    public function test_templates_are_versioned_and_deliveries_record_the_version(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        NotificationTemplate::ensureDefaults();
        $tpl = NotificationTemplate::query()->where('key', 'class.reminder')->where('audience', 'participant')->sole();
        $this->assertSame(1, $tpl->version);
        $this->assertSame(1, $tpl->versions()->count());

        $tpl->update(['title' => 'Besok mulai: {program}']);
        $this->assertSame(2, $tpl->refresh()->version);
        $this->assertSame($admin->id, $tpl->versions()->first()->created_by);
        $tpl->update(['name' => $tpl->name]); // tanpa perubahan isi → versi tetap
        $this->assertSame(2, $tpl->refresh()->version);

        $rina = $this->member('Rina', 'rina@example.com');
        app(NotificationCenter::class)->publish('class.reminder', [Recipient::user($rina->user)], ['program' => 'Cisco CCNA']);
        $d = $this->deliveries('class.reminder')->first();
        $this->assertSame(2, $d->template_version);
        $this->assertSame('Besok mulai: Cisco CCNA', $d->content['title']);

        $tpl->restoreVersion($tpl->versions()->where('version', 1)->sole());
        $this->assertSame(3, $tpl->version);
        $this->assertSame('Besok kelas {program} dimulai', $tpl->title);
        $this->assertSame([3, 2, 1], $tpl->versions()->pluck('version')->all());

        // admin: halaman template (audiens & versi) + riwayat versi; staf tanpa izin ditolak
        $this->get("/admin/notification-templates/{$tpl->id}/edit")->assertOk()->assertSee('Isi WhatsApp');
        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $tpl, 'pageClass' => EditNotificationTemplate::class])
            ->assertOk()->assertSee('Riwayat versi')->assertSee('Besok mulai: {program}')
            ->callTableAction('restore', $tpl->versions()->where('version', 2)->sole())->assertHasNoTableActionErrors();
        $this->assertSame(4, $tpl->refresh()->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification_template.restored', 'actor_id' => $admin->id]);
        $this->get('/admin/notification-templates')->assertOk()->assertSee('Admin perusahaan')->assertSee('Pengingat kelas (H-7)');
        $this->get('/admin/notification-deliveries')->assertOk()->assertSee('Pengiriman 7 hari');
        $this->actingAs($this->admin(User::ROLE_FINANCE))->get('/admin/notification-deliveries')->assertForbidden();
    }

    // ------------------------------------------------------------------ H-7 / H-1: peserta, perusahaan, trainer

    public function test_h7_and_h1_reminders_reach_participants_corporate_and_trainers_once(): void
    {
        $trainerUser = User::factory()->create(['name' => 'Andi Trainer', 'email' => 'andi@vmes.test', 'role' => User::ROLE_TRAINER]);
        $andi = Trainer::query()->create(['user_id' => $trainerUser->id, 'name' => 'Andi Pratama', 'slug' => 'andi', 'phone' => '0811-0000-1111', 'email' => 'andi@vmes.test']);
        $guest = Trainer::query()->create(['name' => 'Bima Asisten', 'slug' => 'bima', 'email' => 'bima@vmes.test']);
        $this->ccna->trainers()->attach([$andi->id => ['role' => 'lead'], $guest->id => ['role' => 'assistant']]);
        $hr = $this->corporateUser($this->abc, 'owner');
        $budi = $this->member('Budi', 'budi@example.com');
        $this->sponsored($budi, $this->abc);

        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->artisan('notifications:reminders')->expectsOutputToContain('Pengingat kelas H-7')->assertSuccessful();
        $this->artisan('notifications:reminders')->assertSuccessful();

        $h7 = $this->deliveries('class.reminder_h7');
        $this->assertSame(1, NotificationEvent::query()->where('key', 'class.reminder_h7')->count());
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $h7->where('user_id', $budi->user_id)->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['database', 'mail'], $h7->where('user_id', $hr->id)->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $h7->where('user_id', $trainerUser->id)->pluck('channel')->all());
        $this->assertSame('6281100001111', $h7->where('user_id', $trainerUser->id)->firstWhere('channel', 'whatsapp')->recipient);
        $guestRows = $h7->where('audience', 'trainer')->whereNull('user_id');
        $this->assertSame(['mail'], $guestRows->pluck('channel')->values()->all(), 'trainer tanpa akun: email saja');
        $this->assertSame('bima@vmes.test', $guestRows->first()->recipient);
        $this->assertSame('sent', $guestRows->first()->status);
        $this->assertStringContainsString('1 peserta terdaftar', $h7->where('user_id', $trainerUser->id)->first()->content['body']);
        $this->assertSame(0, $this->deliveries('class.reminder')->count(), 'H-1 belum');

        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->artisan('notifications:reminders')->assertSuccessful();
        $h1 = $this->deliveries('class.reminder');
        $this->assertSame(['corporate', 'participant', 'trainer'], $h1->pluck('audience')->unique()->sort()->values()->all());
        $this->assertSame('Besok Anda mengajar Cisco CCNA', $h1->where('user_id', $trainerUser->id)->first()->content['title']);
    }

    // ------------------------------------------------------------------ perubahan jadwal: WA + email ke perusahaan & trainer

    public function test_schedule_change_notifies_participants_corporate_and_trainers_by_whatsapp_and_email(): void
    {
        $trainerUser = User::factory()->create(['email' => 'andi@vmes.test', 'role' => User::ROLE_TRAINER]);
        $andi = Trainer::query()->create(['user_id' => $trainerUser->id, 'name' => 'Andi', 'slug' => 'andi', 'phone' => '0811-0000-1111']);
        $this->ccna->trainers()->attach($andi->id, ['role' => 'lead']);
        $hr = $this->corporateUser($this->abc, 'hr_admin');
        $hr->member()->create(['member_number' => 'MBR-HR-1', 'phone' => '0815-5555-6666', 'status' => 'registered']);
        $budi = $this->member('Budi', 'budi@example.com');
        $this->sponsored($budi, $this->abc);

        $this->ccna->refresh()->changeReason = 'Lab renovasi';
        $this->ccna->update(['location' => 'Lab PPTI lantai 3']);

        $rows = $this->deliveries('schedule.changed');
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $rows->where('user_id', $hr->id)->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $rows->where('user_id', $trainerUser->id)->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['database', 'whatsapp'], $rows->where('user_id', $budi->user_id)->pluck('channel')->all(), 'email peserta tetap lewat email transaksional ScheduleChanged');
        $this->assertStringContainsString('Alasan: Lab renovasi', $rows->where('user_id', $hr->id)->firstWhere('channel', 'whatsapp')->content['wa_body']);
        $this->assertTrue($rows->every(fn ($d) => in_array($d->status, ['sent', 'delivered'], true)));
    }

    // ------------------------------------------------------------------ progres berisiko

    public function test_progress_risk_alerts_participant_sponsor_and_admin_once_per_period(): void
    {
        Carbon::setTestNow('2026-11-19 10:00:00'); // hari ke-3 kelas CCNA
        foreach ([1, 2] as $i) {
            ClassSession::query()->create(['schedule_id' => $this->ccna->id, 'session_no' => $i, 'date' => '2026-11-1'.(6 + $i)]);
        }
        $coordinator = $this->admin(User::ROLE_TRAINING_COORDINATOR, 'Koordinator');
        $hr = $this->corporateUser($this->abc, 'hr_admin');
        $budi = $this->member('Budi', 'budi@example.com');
        $this->sponsored($budi, $this->abc);
        $sari = $this->member('Sari', 'sari@example.com');
        $this->selfFunded($sari);

        $this->artisan('notifications:risk')->expectsOutputToContain('Peserta berisiko diperingatkan: 2')->assertSuccessful();
        $this->artisan('notifications:risk')->expectsOutputToContain('Peserta berisiko diperingatkan: 0')->assertSuccessful();

        $rows = $this->deliveries('progress.risk');
        $this->assertEqualsCanonicalizing([$budi->user_id, $sari->user_id], $rows->where('audience', 'participant')->pluck('user_id')->unique()->values()->all());
        $corp = $rows->where('audience', 'corporate');
        $this->assertSame([$hr->id], $corp->pluck('user_id')->unique()->values()->all());
        $this->assertStringContainsString('Budi', $corp->first()->content['title']);
        $this->assertStringContainsString('Kehadiran 0%', $corp->first()->content['body']);
        $admin = $rows->where('audience', 'admin')->where('user_id', $coordinator->id)->sole();
        $this->assertSame('2 peserta berisiko tertinggal', $admin->content['title']);
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $rows->where('user_id', $budi->user_id)->pluck('channel')->all());

        // periode berikutnya → diperingatkan lagi
        Carbon::setTestNow('2026-11-27 10:00:00');
        $this->ccna->update(['end_date' => '2026-11-30']);
        $this->artisan('notifications:risk')->expectsOutputToContain('Peserta berisiko diperingatkan: 2')->assertSuccessful();
    }

    // ------------------------------------------------------------------ enrollment corporate baru

    public function test_sponsored_enrollment_from_collective_registration_notifies_participant_and_sponsor(): void
    {
        $hr = $this->corporateUser($this->abc, 'hr_admin');
        $budi = $this->member('Budi', 'budi@example.com');
        $admin = $this->admin();
        $this->postJson('/api/v1/registrations', ['schedule' => $this->ccna->code, 'type' => 'corporate', 'name' => 'PIC ABC', 'email' => 'pic@abc.test', 'phone' => '0812000',
            'company' => 'PT ABC Manufaktur', 'consent' => true, 'participants_count' => 1, 'participants' => [['name' => 'Budi', 'email' => 'budi@example.com']]])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $svc = app(RegistrationService::class);
        foreach ([RegistrationStatus::UnderReview, RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification] as $to) {
            $svc->transition($reg->fresh(), $to, $admin);
        }
        $reg->update(['organization_id' => $this->abc->id]);
        $reg = $svc->transition($reg->fresh(), RegistrationStatus::Confirmed, $admin);

        $this->assertSame(Enrollment::TYPE_CORPORATE, Enrollment::query()->where('member_id', $budi->id)->sole()->type);
        $rows = $this->deliveries('enrollment.created');
        $p = $rows->where('user_id', $budi->user_id);
        $this->assertEqualsCanonicalizing(['database', 'mail', 'whatsapp'], $p->pluck('channel')->all());
        $this->assertSame('PT ABC Manufaktur mendaftarkan Anda di kelas CCNA-2611, mulai 17 November 2026.', $p->first()->content['body']);
        $this->assertSame('1 peserta terdaftar di CCNA-2611', $rows->where('user_id', $hr->id)->first()->content['title']);
        $this->assertInstanceOf(Registration::class, $reg);
    }
}
