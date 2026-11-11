<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\LmsService;
use App\Services\NotificationEvents;
use App\Services\RegistrationService;
use App\Support\Push\PushSender;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fakes\FakePushSender;
use Tests\TestCase;

/** Phase 5 — Notifikasi & komunikasi (PRD §5.12): aplikasi, email, web push, template, preferensi, log, pengingat, pengumuman. */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $schedule;

    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 08:00:00'); // CCNA-2611 dimulai besok (17 Nov)
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        $this->push = new FakePushSender;
        $this->app->instance(PushSender::class, $this->push);
    }

    /** Akun + pendaftaran individu; $confirm → sampai terkonfirmasi (enrollment dibuat). */
    private function participant(string $email, string $name, bool $confirm = true): array
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $svc = app(RegistrationService::class);
        $reg = $svc->create(['type' => 'individual', 'name' => $name, 'email' => $email, 'phone' => '0812000', 'consent' => true], $this->schedule, $user);
        if ($confirm) {
            foreach ([RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification, RegistrationStatus::Confirmed] as $to) {
                $reg = $svc->transition($reg->refresh(), $to);
            }
        }

        return [$user, $reg];
    }

    private function keys(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['key'])->sort()->values()->all();
    }

    public function test_registration_events_create_in_app_notifications_and_logs(): void
    {
        [$user, $reg] = $this->participant('rina@example.com', 'Rina');
        [$other] = $this->participant('tono@example.com', 'Tono', false);

        $this->assertSame(4, $user->notifications()->count());
        // waktu tes dibekukan → urutan created_at sama; cari berdasarkan isi
        $latest = $user->notifications()->get()->first(fn ($n) => str_contains($n->data['title'], 'Terkonfirmasi'));
        $this->assertSame('registration.status_changed', $latest->data['key']);
        $this->assertStringContainsString('Terkonfirmasi', $latest->data['title']);
        $this->assertSame('/portal/pendaftaran/'.$reg->registration_number, $latest->data['url']);

        // email transaksional & notifikasi aplikasi tercatat di log
        $this->assertDatabaseHas('notification_logs', ['notification' => 'RegistrationReceived', 'channel' => 'mail', 'recipient' => 'rina@example.com', 'status' => 'sent', 'category' => 'registration']);
        $this->assertDatabaseHas('notification_logs', ['notification' => 'registration.status_changed', 'channel' => 'database', 'user_id' => $user->id, 'status' => 'sent']);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.unreadNotifications', 4);
        $res = $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('meta.unread', 4)->assertJsonCount(4, 'data');
        // urutan stabil (UUIDv7) walau created_at sama: terbaru di atas
        $this->assertStringContainsString('Terkonfirmasi', $res->json('data.0.title'));
        $this->assertSame('registration.received', $res->json('data.3.key'));
        $id = $res->json('data.0.id');
        $this->assertFalse($res->json('data.0.read'));
        $this->postJson("/api/v1/me/notifications/$id/read")->assertOk()->assertJsonPath('unread', 3)->assertJsonPath('data.read', true);
        $this->getJson('/api/v1/me/notifications?filter=unread')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/v1/me/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);

        // notifikasi milik orang lain tidak bisa diakses
        $foreign = $other->notifications()->first()->id;
        $this->postJson("/api/v1/me/notifications/$foreign/read")->assertNotFound();
    }

    public function test_preferences_control_optional_channels_and_reminders_are_deduplicated(): void
    {
        [$rina] = $this->participant('rina@example.com', 'Rina');
        [$tono] = $this->participant('tono@example.com', 'Tono');

        Sanctum::actingAs($rina);
        $settings = $this->getJson('/api/v1/me/notification-settings')->assertOk()->json('data');
        $this->assertCount(count(NotificationTemplate::CATEGORIES), $settings['categories']);
        $this->assertTrue($settings['categories'][1]['mail']);
        $this->putJson('/api/v1/me/notification-settings', ['preferences' => ['schedule' => ['mail' => false, 'push' => true]]])->assertOk()
            ->assertJsonPath('data.categories.1.key', 'schedule')->assertJsonPath('data.categories.1.mail', false);
        $this->putJson('/api/v1/me/notification-settings', ['preferences' => ['tidak-ada' => ['mail' => false]]])->assertStatus(422);

        $this->artisan('notifications:reminders')->assertSuccessful();
        $this->artisan('notifications:reminders')->assertSuccessful(); // ulang → tidak dobel

        foreach ([$rina, $tono] as $u) {
            $this->assertSame(1, $u->notifications()->get()->where('data.key', 'class.reminder')->count());
        }
        $reminder = $tono->notifications()->get()->firstWhere('data.key', 'class.reminder');
        $this->assertSame('Besok kelas Cisco CCNA dimulai', $reminder->data['title']);
        $this->assertStringContainsString('17 November 2026', $reminder->data['body']);
        // Tono menerima email pengingat, Rina tidak (preferensi)
        $this->assertDatabaseHas('notification_logs', ['notification' => 'class.reminder', 'channel' => 'mail', 'user_id' => $tono->id, 'status' => 'sent']);
        $this->assertDatabaseMissing('notification_logs', ['notification' => 'class.reminder', 'channel' => 'mail', 'user_id' => $rina->id]);

        // template dinonaktifkan admin → tidak terkirim
        NotificationTemplate::query()->where('key', 'class.reminder')->update(['is_active' => false]);
        $this->assertSame(0, app(NotificationEvents::class)->classReminders(Carbon::parse('2026-11-17')));
    }

    public function test_web_push_subscription_delivery_and_failures(): void
    {
        [$user] = $this->participant('rina@example.com', 'Rina', false);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notification-settings')->assertOk()
            ->assertJsonPath('data.push.available', true)->assertJsonPath('data.push.publicKey', 'BFakePublicKeyForTests')->assertJsonPath('data.push.devices', 0);
        $sub = fn (string $endpoint) => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'authsecret'], 'contentEncoding' => 'aes128gcm'];
        $this->postJson('/api/v1/me/push-subscriptions', $sub('http://push.example.com/x'))->assertStatus(422);
        $this->postJson('/api/v1/me/push-subscriptions/test')->assertStatus(422);
        $this->postJson('/api/v1/me/push-subscriptions', $sub('https://push.example.com/ok-1'))->assertCreated()->assertJsonPath('data.devices', 1);
        $this->postJson('/api/v1/me/push-subscriptions', $sub('https://push.example.com/ok-1'))->assertCreated()->assertJsonPath('data.devices', 1); // idempoten
        $this->postJson('/api/v1/me/push-subscriptions', $sub('https://push.example.com/expired-1'))->assertCreated()->assertJsonPath('data.devices', 2);

        $this->postJson('/api/v1/me/push-subscriptions/test')->assertOk()->assertJsonPath('data.sent', true);
        $this->assertCount(1, $this->push->sent);
        $this->assertSame('Notifikasi push aktif', $this->push->sent[0]['payload']['title']);
        $this->assertSame(1, $user->pushSubscriptions()->count(), 'langganan kedaluwarsa (410) dihapus');
        $this->assertDatabaseHas('notification_logs', ['notification' => 'push.test', 'channel' => 'webpush', 'status' => 'sent', 'recipient' => '1 perangkat']);

        // peristiwa biasa → aplikasi + push (url ikut di payload)
        app(RegistrationService::class)->transition(Registration::query()->first(), RegistrationStatus::UnderReview);
        $payload = end($this->push->sent)['payload'];
        $this->assertStringStartsWith('/portal/pendaftaran/REG-', $payload['url']);
        $this->assertSame('registration.status_changed', $payload['tag']);

        // semua perangkat gagal → log gagal, notifikasi aplikasi tetap masuk
        $user->pushSubscriptions()->delete();
        $this->postJson('/api/v1/me/push-subscriptions', $sub('https://push.example.com/fail-1'))->assertCreated();
        $this->postJson('/api/v1/me/push-subscriptions/test')->assertStatus(502);
        $this->assertDatabaseHas('notification_logs', ['notification' => 'push.test', 'channel' => 'webpush', 'status' => 'failed', 'error' => 'Push gagal: HTTP 500 Server error']);

        $this->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => 'https://push.example.com/fail-1'])->assertOk()->assertJsonPath('data.devices', 0);

        $this->app->instance(PushSender::class, new FakePushSender(false));
        $this->postJson('/api/v1/me/push-subscriptions', $sub('https://push.example.com/ok-2'))->assertStatus(503);
    }

    public function test_learning_schedule_and_certificate_events(): void
    {
        [$user] = $this->participant('rina@example.com', 'Rina');
        $enrollment = Enrollment::query()->firstOrFail();
        $course = Course::query()->create(['package_id' => $this->schedule->package_id, 'title' => 'CCNA Online', 'slug' => 'ccna-online', 'is_published' => true]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Modul 1']);

        Lesson::query()->create(['module_id' => $module->id, 'title' => 'Draf', 'type' => 'text', 'is_published' => false]);
        $lesson = Lesson::query()->create(['module_id' => $module->id, 'title' => 'Dasar Routing', 'type' => 'text', 'is_published' => true]);
        $lesson->update(['title' => 'Dasar Routing & Switching']);
        $lesson->update(['is_published' => false]);
        $lesson->update(['is_published' => true]);
        $published = $user->notifications()->get()->where('data.key', 'lesson.published');
        $this->assertCount(1, $published, 'materi baru hanya diberitahukan sekali');
        $this->assertSame("/portal/belajar/ccna-online/{$lesson->id}", $published->first()->data['url']);

        // tenggat tugas < 24 jam, belum dikumpulkan
        $task = Lesson::query()->create(['module_id' => $module->id, 'title' => 'Lab VLAN', 'type' => 'assignment', 'is_published' => true,
            'settings' => ['due_at' => now()->addHours(6)->format('Y-m-d H:i:s'), 'max_score' => 100]]);
        $this->assertSame(1, app(NotificationEvents::class)->assignmentDueReminders());
        $this->assertSame(0, app(NotificationEvents::class)->assignmentDueReminders());

        $sub = AssignmentSubmission::query()->create(['lesson_id' => $task->id, 'enrollment_id' => $enrollment->id, 'text' => 'Jawaban', 'status' => 'submitted', 'submitted_at' => now()]);
        app(LmsService::class)->grade($sub, 85, 'Konfigurasi rapi', 'graded', User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));
        $graded = $user->notifications()->get()->firstWhere('data.key', 'assignment.graded');
        $this->assertSame('Tugas Lab VLAN sudah dinilai', $graded->data['title']);
        $this->assertStringContainsString('Nilai: 85/100.', $graded->data['body']);
        $this->assertStringContainsString('Catatan: Konfigurasi rapi', $graded->data['body']);

        // perubahan jadwal
        $this->schedule->refresh()->changeReason = 'Ruang lab dipindah';
        $this->schedule->update(['location' => 'Lab PPTI Batam lantai 3']);
        $changed = $user->notifications()->get()->firstWhere('data.key', 'schedule.changed');
        $this->assertStringContainsString('Lokasi', $changed->data['body']);
        $this->assertStringContainsString('Alasan: Ruang lab dipindah', $changed->data['body']);

        // sertifikat terbit
        $cert = Certificate::query()->create(['number' => 'PPTI-PST-202611-0001', 'verification_code' => 'ABCD1234', 'enrollment_id' => $enrollment->id,
            'recipient_name' => 'Rina', 'program_title' => 'Cisco CCNA', 'status' => 'issued']);
        app(NotificationEvents::class)->certificateIssued($cert);
        $this->assertSame('/portal/sertifikat/PPTI-PST-202611-0001', $user->notifications()->get()->firstWhere('data.key', 'certificate.issued')->data['url']);
    }

    public function test_admin_templates_logs_and_announcement(): void
    {
        [$user] = $this->participant('rina@example.com', 'Rina');
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $this->actingAs($finance)->get('/admin/notification-templates')->assertForbidden();
        $this->actingAs($finance)->get('/admin/notification-logs')->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->actingAs($admin)->get('/admin/notification-templates')->assertOk()->assertSee('Pengingat kelas (H-1)');
        $this->assertSame(count(NotificationTemplate::defaults()), NotificationTemplate::query()->count());
        $tpl = NotificationTemplate::query()->where('key', 'announcement')->first();
        $this->actingAs($admin)->get("/admin/notification-templates/{$tpl->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/admin/notification-logs')->assertOk()->assertSee('RegistrationReceived');

        Livewire::actingAs($admin)->test(ListNotificationTemplates::class)
            ->callAction('announce', data: ['target' => 'class', 'schedule_id' => $this->schedule->id, 'title' => 'Bawa laptop', 'message' => 'Peserta wajib membawa laptop dengan Packet Tracer.', 'url' => '/portal/pelatihan'])
            ->assertHasNoActionErrors();

        $n = $user->notifications()->get()->firstWhere('data.key', 'announcement');
        $this->assertSame('Bawa laptop', $n->data['title']);
        $this->assertSame('/portal/pelatihan', $n->data['url']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.announcement']);
        $this->assertTrue(NotificationLog::query()->where('notification', 'announcement')->where('channel', 'mail')->exists());
    }
}
