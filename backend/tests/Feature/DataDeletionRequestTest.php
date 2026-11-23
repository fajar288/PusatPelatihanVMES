<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\DataDeletionRequests\DataDeletionRequestResource;
use App\Filament\Resources\DataDeletionRequests\Pages\ViewDataDeletionRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\DataDeletionRequest;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Page;
use App\Models\PaymentRefund;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CompletionService;
use App\Services\DataDeletionService;
use App\Services\MemberService;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Permintaan penghapusan data pribadi: alur lengkap (ajukan -> setujui -> selesai), pembatalan, penolakan otomatis,
 * login ditolak setelah selesai, invoice tetap, berkas terhapus, sertifikat tetap sah dengan nama disamarkan,
 * email terakhir sebelum email dianonimkan, audit, dan akses per peran.
 */
class DataDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $schedule;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 09:00:00'); // kelas CCNA-2611 (17-21 Nov 2026) sudah selesai
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(config('filesystems.default'));
        Storage::fake(Registration::DOCUMENT_DISK);
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        foreach ([1 => '2026-11-17', 2 => '2026-11-18', 3 => '2026-11-19', 4 => '2026-11-20', 5 => '2026-11-21'] as $no => $date) {
            ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => $no, 'date' => $date, 'topic' => "Sesi $no"]);
        }
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'name' => 'Admin Privasi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Alumni: pendaftaran berdokumen, invoice lunas, lulus, sertifikat terbit, foto profil. */
    private function alumnus(string $email = 'budi@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Budi Santoso', 'password' => 'rahasia123']);
        app(MemberService::class)->createForUser($user, ['phone' => '081234567890', 'company' => 'PT Maju', 'address' => 'Jl. Melati 1']);
        $this->actingAs($user, 'sanctum')->post('/api/v1/registrations', [
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $user->name, 'email' => $email, 'phone' => '081234567890', 'consent' => true,
            'documents' => [UploadedFile::fake()->create('ktp.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $svc = app(RegistrationService::class);
        foreach ([RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification, RegistrationStatus::Confirmed] as $to) {
            $svc->transition($reg, $to, $this->admin);
        }
        Invoice::query()->where('registration_id', $reg->id)->update(['status' => 'paid', 'amount_paid' => DB::raw('total'), 'paid_at' => now()]);
        $enrollment = Enrollment::query()->where('registration_id', $reg->id)->firstOrFail();
        foreach ($this->schedule->sessions()->get() as $s) {
            Attendance::query()->create(['session_id' => $s->id, 'enrollment_id' => $enrollment->id, 'status' => 'present']);
        }
        app(CompletionService::class)->complete($enrollment, $this->admin, pass: true, finalScore: 88);
        $certs = app(CertificateService::class);
        $cert = $certs->createDraft($enrollment, $this->admin);
        $certs->submit($cert, $this->admin);
        $certs->issue($cert, $this->admin);
        app(MemberService::class)->updatePhoto($user->refresh()->member, UploadedFile::fake()->image('foto.jpg', 300, 300), $user);

        return $user->refresh();
    }

    public function test_full_flow_erases_identity_keeps_invoice_and_certificate(): void
    {
        $user = $this->alumnus();
        $member = $user->member;
        $reg = Registration::query()->where('user_id', $user->id)->firstOrFail();
        $invoice = Invoice::query()->where('registration_id', $reg->id)->firstOrFail();
        $cert = Certificate::query()->where('member_id', $member->id)->firstOrFail();
        $docPath = $reg->documentList()[0]['path'];
        $photoPath = $member->photo;
        $this->assertNotNull($cert->pdf_path);
        Storage::disk(config('filesystems.default'))->assertExists($cert->pdf_path);
        Storage::disk(MemberService::PHOTO_DISK)->assertExists($photoPath);
        $token = $user->createToken('portal')->plainTextToken;

        // Portal: penjelasan + tanpa penghalang, ada peringatan sertifikat
        $show = $this->withToken($token)->getJson('/api/v1/me/data-deletion')->assertOk();
        $show->assertJsonPath('data.request', null)->assertJsonPath('data.canRequest', true)->assertJsonPath('data.blockers', [])
            ->assertJsonPath('data.deadlineDays', 14)->assertJsonPath('data.warnings.0.code', 'certificates');
        $this->assertNotEmpty($show->json('data.policy.kept'));

        // Konfirmasi salah ditolak, benar diterima
        $this->withToken($token)->postJson('/api/v1/me/data-deletion', ['email' => 'salah@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson('/api/v1/me/data-deletion', ['password' => 'bukan-ini'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/v1/me/data-deletion', ['email' => 'BUDI@example.com', 'reason' => 'Tidak memakai layanan lagi'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.cancellable', true)->assertJsonPath('data.dueLabel', '6 Desember 2026');
        $this->withToken($token)->postJson('/api/v1/me/data-deletion', ['email' => 'budi@example.com'])->assertUnprocessable()->assertJsonValidationErrors('request');
        $req = DataDeletionRequest::query()->firstOrFail();
        $this->assertSame($member->member_number, $req->account_label);

        // Notifikasi: peserta (diterima) dan admin berizin (permintaan baru)
        $this->assertTrue(NotificationDelivery::query()->where('user_id', $user->id)->whereHas('event', fn ($q) => $q->where('key', 'privacy.deletion_received'))->where('channel', 'mail')->exists());
        $this->assertTrue(NotificationDelivery::query()->where('user_id', $this->admin->id)->whereHas('event', fn ($q) => $q->where('key', 'privacy.deletion_requested'))->exists());

        // Email terakhir terkirim saat email akun belum dianonimkan
        $emailWhenFinalSent = null;
        Event::listen('eloquent.updated: '.NotificationDelivery::class, function (NotificationDelivery $d) use (&$emailWhenFinalSent, $user) {
            if ($d->status === 'sent' && $d->event?->key === 'privacy.deletion_completed') {
                $emailWhenFinalSent = User::query()->whereKey($user->id)->value('email');
            }
        });

        // Admin menyetujui lewat halaman detail Filament
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->admin);
        Livewire::test(ViewDataDeletionRequest::class, ['record' => $req->id])->callAction('approve', ['note' => 'Diproses sesuai permintaan']);
        $req->refresh();
        $this->assertSame('completed', $req->status);
        $this->assertSame($this->admin->id, $req->processed_by);
        $this->assertNotNull($req->completed_at);
        $this->assertSame('budi@example.com', $emailWhenFinalSent);

        // Identitas akun & member hilang, login dimatikan, token dicabut
        $user->refresh();
        $this->assertSame('Data dihapus', $user->name);
        $this->assertStringEndsWith('@invalid.local', $user->email);
        $this->assertFalse($user->isActive());
        $this->assertSame(0, $user->tokens()->count());
        $member->refresh();
        $this->assertNull($member->phone);
        $this->assertNull($member->address);
        $this->assertNull($member->company);
        $this->assertNull($member->photo);
        $this->assertSame('alumni', $member->status, 'statistik alumni tetap');
        Storage::disk(MemberService::PHOTO_DISK)->assertMissing($photoPath);
        Storage::disk(Registration::DOCUMENT_DISK)->assertMissing($docPath);

        // Pendaftaran & enrollment tetap terhitung tanpa identitas
        $reg->refresh();
        $this->assertSame('Data dihapus', $reg->name);
        $this->assertSame([], $reg->documentList());
        $this->assertSame(RegistrationStatus::Confirmed, $reg->status);
        $enrollment = Enrollment::query()->where('registration_id', $reg->id)->firstOrFail();
        $this->assertSame('Data dihapus', $enrollment->participant_name);
        $this->assertSame('completed', $enrollment->status);
        $this->assertEquals(88, $enrollment->final_score);

        // Invoice tetap (catatan akuntansi), hanya email penagihan dilepas
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('Budi Santoso', $invoice->bill_to_name);
        $this->assertNull($invoice->bill_to_email);

        // Sertifikat tetap sah, nama tidak tampil di verifikasi publik, bisa dicocokkan, PDF server dihapus
        $cert->refresh();
        $this->assertSame('issued', $cert->status);
        $this->assertTrue($cert->isRecipientHidden());
        Storage::disk(config('filesystems.default'))->assertMissing('certificates/2026/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $cert->number).'.pdf');
        $this->getJson('/api/v1/verify/'.$cert->number)->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.recipientName', null)->assertJsonPath('data.nameHidden', true);
        $this->postJson('/api/v1/verify/'.$cert->number.'/nama', ['name' => '  budi   SANTOSO '])->assertOk()->assertJsonPath('data.matches', true);
        $this->postJson('/api/v1/verify/'.$cert->number.'/nama', ['name' => 'Andi Wijaya'])->assertOk()->assertJsonPath('data.matches', false);
        try {
            app(CertificateService::class)->storePdf($cert);
            $this->fail('PDF sertifikat tersamar tidak boleh dibuat ulang.');
        } catch (ValidationException) {
        }

        // Login dengan kredensial lama dan token lama ditolak
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'budi@example.com', 'password' => 'rahasia123'])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        // Nama/email tidak tersisa di log pengiriman notifikasi
        $this->assertFalse(NotificationDelivery::query()->where('recipient', 'budi@example.com')->exists());
        $this->assertFalse(NotificationDelivery::query()->get()->contains(fn ($d) => str_contains(json_encode($d->content), 'Budi Santoso')));

        // Audit lengkap
        foreach (['privacy.deletion_requested', 'privacy.deletion_approved', 'privacy.account_erased'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
        $erased = AuditLog::query()->where('action', 'privacy.account_erased')->firstOrFail();
        $this->assertSame(1, $erased->after['certificates']);
        $this->assertSame($this->admin->id, $erased->actor_id);
    }

    public function test_participant_can_cancel_pending_request_only(): void
    {
        $user = $this->alumnus();
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/me/data-deletion/cancel')->assertUnprocessable();
        $this->postJson('/api/v1/me/data-deletion', ['password' => 'rahasia123'])->assertCreated();
        $this->postJson('/api/v1/me/data-deletion/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellable', false);
        $this->assertTrue(AuditLog::query()->where('action', 'privacy.deletion_cancelled')->exists());
        $this->getJson('/api/v1/me/data-deletion')->assertJsonPath('data.canRequest', true)->assertJsonPath('data.request.status', 'cancelled');

        // setelah dibatalkan boleh mengajukan lagi; yang sudah ditolak admin tidak bisa dibatalkan
        $this->postJson('/api/v1/me/data-deletion', ['email' => 'budi@example.com'])->assertCreated();
        $req = DataDeletionRequest::query()->latest('id')->firstOrFail();
        app(DataDeletionService::class)->reject($req, $this->admin, 'Masih ada sengketa pembayaran.');
        $this->postJson('/api/v1/me/data-deletion/cancel')->assertUnprocessable();
        $this->assertSame('Budi Santoso', $user->refresh()->name, 'data tidak berubah');
        $this->assertTrue(AuditLog::query()->where('action', 'privacy.deletion_rejected')->exists());
        $this->assertTrue(NotificationDelivery::query()->where('user_id', $user->id)->whereHas('event', fn ($q) => $q->where('key', 'privacy.deletion_rejected'))->exists());
    }

    public function test_running_enrollment_unpaid_invoice_and_refund_block_and_auto_reject(): void
    {
        // pendaftaran baru di kelas mendatang yang masih menunggu pembayaran
        $user = User::factory()->create(['email' => 'sari@example.com', 'name' => 'Sari']);
        $upcoming = TrainingSchedule::query()->whereDate('start_date', '>', today())->where('status', 'registration_open')->orderBy('start_date')->firstOrFail();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => $upcoming->code, 'type' => 'individual', 'name' => 'Sari', 'email' => 'sari@example.com', 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        app(RegistrationService::class)->transition($reg, RegistrationStatus::WaitingPayment, $this->admin);

        $codes = array_column(app(DataDeletionService::class)->blockers($user), 'code');
        $this->assertContains('registration_in_progress', $codes);
        $this->assertContains('invoice_unpaid', $codes);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/data-deletion', ['email' => 'sari@example.com'])->assertCreated();
        $res->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.autoRejected', true)->assertJsonPath('data.statusLabel', 'Ditolak otomatis');
        $this->assertContains('invoice_unpaid', array_column($res->json('data.blockers'), 'code'));
        $this->assertTrue(AuditLog::query()->where('action', 'privacy.deletion_auto_rejected')->exists());
        $this->getJson('/api/v1/me/data-deletion')->assertJsonPath('data.canRequest', true);

        // lunas + terkonfirmasi: enrollment kelas mendatang masih berjalan
        Invoice::query()->where('registration_id', $reg->id)->update(['status' => 'paid', 'paid_at' => now()]);
        $svc = app(RegistrationService::class);
        $svc->transition($reg->refresh(), RegistrationStatus::PaymentVerification, $this->admin);
        $svc->transition($reg->refresh(), RegistrationStatus::Confirmed, $this->admin);
        $this->assertSame(['enrollment_running'], array_column(app(DataDeletionService::class)->blockers($user), 'code'));

        // refund yang sedang diproses
        Enrollment::query()->where('registration_id', $reg->id)->update(['status' => 'dropped']);
        PaymentRefund::query()->create(['code' => 'RFD-202611-0001', 'invoice_id' => Invoice::query()->where('registration_id', $reg->id)->value('id'), 'amount' => 100000, 'reason' => 'Batal', 'status' => 'requested', 'requested_at' => now()]);
        $this->assertSame(['refund_in_progress'], array_column(app(DataDeletionService::class)->blockers($user), 'code'));

        // akun staf tidak bisa menghapus diri lewat portal
        $this->assertSame(['staff_account'], array_column(app(DataDeletionService::class)->blockers($this->admin), 'code'));
    }

    public function test_approval_rechecks_blockers(): void
    {
        $user = $this->alumnus();
        $req = app(DataDeletionService::class)->request($user, ['email' => 'budi@example.com']);
        $this->assertSame('pending', $req->status);

        // setelah mengajukan, peserta mendaftar kelas baru: persetujuan tertahan dengan alasannya
        $upcoming = TrainingSchedule::query()->whereDate('start_date', '>', today())->where('status', 'registration_open')->orderBy('start_date')->firstOrFail();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => $upcoming->code, 'type' => 'individual', 'name' => 'Budi Santoso', 'email' => 'budi@example.com', 'phone' => '0812', 'consent' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        try {
            app(DataDeletionService::class)->approve($req, $this->admin);
            $this->fail('Persetujuan harus tertahan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Pendaftaran masih diproses', collect($e->errors())->flatten()->first());
        }
        $this->assertSame('pending', $req->refresh()->status);
        $this->assertSame('Budi Santoso', $user->refresh()->name);
    }

    public function test_only_privacy_managers_can_process_and_near_deadline_is_flagged(): void
    {
        $user = $this->alumnus();
        $req = app(DataDeletionService::class)->request($user, ['email' => 'budi@example.com']);
        $this->app['auth']->shouldUse('web'); // alumnus() memakai guard sanctum

        foreach ([User::ROLE_MANAGEMENT, User::ROLE_MARKETING, User::ROLE_FINANCE, User::ROLE_TRAINING_COORDINATOR] as $role) {
            $staff = User::factory()->create(['role' => $role]);
            $this->actingAs($staff);
            $this->assertFalse(DataDeletionRequestResource::canViewAny(), $role);
            $this->get('/admin/permintaan-hapus-data')->assertForbidden();
            try {
                app(DataDeletionService::class)->approve($req, $staff);
                $this->fail("$role tidak boleh menyetujui");
            } catch (ValidationException) {
            }
            $this->app['auth']->forgetGuards();
        }
        $this->assertSame('pending', $req->refresh()->status);

        $this->actingAs($this->admin);
        $this->assertTrue(DataDeletionRequestResource::canViewAny());
        $this->get('/admin/permintaan-hapus-data')->assertOk()->assertSee($req->account_label);
        $this->get('/admin/permintaan-hapus-data/'.$req->id)->assertOk()->assertSee('Penghalang dan peringatan')->assertSee('Budi Santoso');
        $this->assertNull(DataDeletionRequestResource::getNavigationBadge());

        // 12 hari kemudian: tinggal 2 hari dari tenggat 14 hari
        Carbon::setTestNow(now()->addDays(12));
        $this->assertSame('1', DataDeletionRequestResource::getNavigationBadge());
        $this->assertTrue($req->refresh()->isNearDeadline());
        $this->assertFalse($req->isOverdue());
        Carbon::setTestNow(now()->addDays(3));
        $this->assertTrue($req->refresh()->isOverdue());

        // tolak lewat aksi Filament: alasan wajib
        Livewire::test(ViewDataDeletionRequest::class, ['record' => $req->id])->callAction('reject', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        Livewire::test(ViewDataDeletionRequest::class, ['record' => $req->id])->callAction('reject', ['reason' => 'Identitas belum dapat dipastikan.']);
        $this->assertSame('rejected', $req->refresh()->status);
        $this->assertSame('Identitas belum dapat dipastikan.', $req->admin_note);
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', 'privacy.deletion_rejected')->value('actor_id'));
    }

    public function test_deadline_is_configurable(): void
    {
        config(['privacy.deletion_deadline_days' => 30]);
        $user = $this->alumnus();
        $req = app(DataDeletionService::class)->request($user, ['email' => 'budi@example.com']);
        $this->assertSame('2026-12-22', $req->due_at->toDateString());
    }

    public function test_privacy_policy_migration_points_to_portal_and_respects_admin_edits(): void
    {
        $page = Page::query()->where('slug', 'kebijakan-privasi')->firstOrFail();
        $old = '<p>Anda berhak mengakses, memperbaiki, atau meminta penghapusan data pribadi Anda, serta menarik persetujuan pemasaran. Ajukan permintaan melalui WhatsApp resmi atau email yang tercantum di halaman Kontak.</p>';
        $page->update(['body' => "<p>Paragraf lain.</p>\n$old"]);
        $migration = require database_path('migrations/2026_11_23_100100_point_privacy_policy_to_portal_deletion.php');

        $migration->up();
        $this->assertStringContainsString('menu Privasi &amp; Data', $page->refresh()->body);
        $this->assertStringStartsWith('<p>Paragraf lain.</p>', $page->body);
        $migration->up(); // idempoten
        $migration->down();
        $this->assertSame("<p>Paragraf lain.</p>\n$old", $page->refresh()->body);

        // halaman yang paragrafnya sudah diedit admin tidak disentuh
        $page->update(['body' => '<p>Hubungi kami untuk hak data Anda.</p>']);
        $migration->up();
        $this->assertSame('<p>Hubungi kami untuk hak data Anda.</p>', $page->refresh()->body);
    }
}
