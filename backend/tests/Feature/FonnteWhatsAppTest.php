<?php

namespace Tests\Feature;

use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Widgets\WhatsAppStatusWidget;
use App\Models\NotificationDelivery;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\NotificationCenter;
use App\Support\Notify\Recipient;
use App\Support\WhatsApp\FonnteWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppSender;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Driver WhatsApp Fonnte: kirim (form-encoded, Authorization tanpa Bearer, countryCode string), galat sementara → retry,
 * galat permanen → langsung gagal, binding driver, webhook status pesan & device (secret di path), widget status,
 * kirim WA uji. Semua lewat Http::fake() — tidak ada request ke internet.
 */
class FonnteWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok-RAHASIA-9f8e7d';

    private const SECRET = 'whsec-0123456789abcdef';

    private const SEND_URL = 'https://api.fonnte.com/send';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 08:00:00');
        $this->seed(RolesSeeder::class);
        config([
            'notifications.whatsapp.enabled' => true,
            'notifications.whatsapp.driver' => 'fonnte',
            'notifications.whatsapp.fonnte.token' => self::TOKEN,
            'notifications.whatsapp.fonnte.webhook_secret' => self::SECRET,
        ]);
        $this->app->forgetInstance(WhatsAppSender::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function success(string $id = '80367170'): array
    {
        return ['detail' => 'success! message in queue', 'id' => [$id], 'process' => 'pending', 'requestid' => 2937124, 'status' => true, 'target' => ['6281234567890']];
    }

    /** Satu pengiriman WA lewat Notification Engine (trainer tanpa akun/email → hanya kanal WhatsApp). */
    private function publishWa(): NotificationDelivery
    {
        app(NotificationCenter::class)->publish('schedule.changed', [new Recipient(Recipient::TRAINER, null, 'Budi Trainer', null, '0812-3456-7890')], [
            'program' => 'Cisco CCNA', 'class' => 'CCNA-2611', 'changes' => 'Mulai pukul 09.00', 'reason' => 'Ruang dipakai',
        ]);

        return NotificationDelivery::query()->where('channel', 'whatsapp')->latest('id')->firstOrFail();
    }

    private function dispatchAt(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
        $this->artisan('notifications:dispatch')->assertSuccessful();
    }

    // ------------------------------------------------------------------ kirim

    public function test_send_success_uses_plain_token_form_body_and_string_country_code(): void
    {
        Http::fake([self::SEND_URL => Http::response(self::success())]);

        $d = $this->publishWa();

        $this->assertSame('sent', $d->status);
        $this->assertSame('fonnte', $d->provider);
        $this->assertSame('80367170', $d->provider_message_id);
        $this->assertSame('6281234567890', $d->recipient);
        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === self::SEND_URL && $r->method() === 'POST'
                && $r->header('Authorization') === [self::TOKEN]               // token polos, tanpa "Bearer"
                && $r->isForm() && str_contains($r->body(), 'countryCode=62')
                && $r['countryCode'] === '62' && $r['connectOnly'] === 'true'
                && $r['target'] === '6281234567890' && str_contains($r['message'], 'Cisco CCNA');
        });
        $this->assertDatabaseHas('notification_logs', ['delivery_id' => $d->id, 'attempt' => 1, 'status' => 'sent']);
    }

    public function test_temporary_failure_is_retried_with_attempt_log(): void
    {
        Http::fake([self::SEND_URL => Http::sequence()
            ->push(['reason' => 'device disconnected', 'status' => false, 'requestid' => 1])   // alasan non-permanen
            ->push(self::success('555'))]);

        $d = $this->publishWa();

        $this->assertSame('queued', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertEquals(now()->addMinute(), $d->next_attempt_at);
        $this->assertSame('Fonnte: device disconnected', $d->last_error);
        $this->assertDatabaseHas('notification_logs', ['delivery_id' => $d->id, 'attempt' => 1, 'status' => 'failed', 'error' => 'Fonnte: device disconnected']);

        $this->dispatchAt(1);
        $d->refresh();
        $this->assertSame('sent', $d->status);
        $this->assertSame(2, $d->attempts);
        $this->assertSame('555', $d->provider_message_id);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function permanentFailures(): array
    {
        return [
            'token invalid ("Status" huruf besar)' => [['reason' => 'token invalid', 'Status' => false], 'Fonnte: token invalid'],
            'insufficient quota' => [['reason' => 'insufficient quota', 'status' => false, 'requestid' => 9], 'Fonnte: insufficient quota'],
            'target invalid' => [['reason' => 'target invalid', 'status' => false], 'Fonnte: target invalid'],
        ];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_failure_marks_failed_without_retry(array $body, string $error): void
    {
        Http::fake([self::SEND_URL => Http::response($body)]);

        $d = $this->publishWa();

        $this->assertSame('failed', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertNull($d->next_attempt_at);
        $this->assertNotNull($d->failed_at);
        $this->assertSame($error, $d->last_error);
        $this->dispatchAt(200);
        Http::assertSentCount(1);
        $this->assertSame(1, NotificationLog::query()->where('delivery_id', $d->id)->where('status', 'failed')->count());
    }

    public function test_timeout_http_500_and_missing_id_are_retried(): void
    {
        Http::fake([self::SEND_URL => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push('Internal Server Error', 500)
            ->push(['status' => true, 'id' => []])
            ->push(self::success('777'))]);

        $d = $this->publishWa();
        $this->assertSame('queued', $d->status);
        $this->assertSame('Fonnte: koneksi gagal atau timeout.', $d->last_error);

        $this->dispatchAt(1);
        $this->assertSame('Fonnte: HTTP 500.', $d->refresh()->last_error);
        $this->assertSame('queued', $d->status);

        $this->dispatchAt(5);
        $this->assertSame('Fonnte: respons sukses tanpa ID pesan.', $d->refresh()->last_error);

        $this->dispatchAt(30);
        $d->refresh();
        $this->assertSame('sent', $d->status);
        $this->assertSame(4, $d->attempts);
        $this->assertSame('777', $d->provider_message_id);
        $this->assertSame([1, 2, 3], NotificationLog::query()->where('delivery_id', $d->id)->where('status', 'failed')->orderBy('attempt')->pluck('attempt')->all());
    }

    // ------------------------------------------------------------------ binding & konfigurasi

    public function test_driver_binding_follows_config_and_defaults_to_log(): void
    {
        $resolve = function (?string $driver): WhatsAppSender {
            config(['notifications.whatsapp.driver' => $driver]);
            $this->app->forgetInstance(WhatsAppSender::class);

            return app(WhatsAppSender::class);
        };
        $this->assertInstanceOf(FonnteWhatsAppSender::class, $resolve('fonnte'));
        $this->assertInstanceOf(LogWhatsAppSender::class, $resolve('log'));

        Log::spy();
        $this->assertInstanceOf(LogWhatsAppSender::class, $resolve('twilio-entah'));
        Log::shouldHaveReceived('warning')->once();

        // tanpa token kanal Fonnte tidak aktif walau WHATSAPP_ENABLED=true
        config(['notifications.whatsapp.fonnte.token' => '']);
        $this->assertFalse($resolve('fonnte')->enabled());
        config(['notifications.whatsapp.fonnte.token' => self::TOKEN, 'notifications.whatsapp.enabled' => false]);
        $this->assertFalse($resolve('fonnte')->enabled());
    }

    public function test_default_config_keeps_log_driver_and_channel_off(): void
    {
        $config = require config_path('notifications.php');
        $this->assertSame('log', $config['whatsapp']['driver']);
        $this->assertFalse($config['whatsapp']['enabled']);
        $env = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString("WHATSAPP_ENABLED=false\nWHATSAPP_DRIVER=log", str_replace("\r\n", "\n", $env));
        $this->assertMatchesRegularExpression('/^FONNTE_TOKEN=$/m', str_replace("\r\n", "\n", $env));
        $this->assertSame('6281234567890', PhoneNumber::normalize('+62 812-3456-7890'));
        $this->assertNull(PhoneNumber::normalize('0812'));
    }

    // ------------------------------------------------------------------ webhook status pesan

    public function test_webhook_rejects_wrong_or_unset_secret(): void
    {
        $this->postJson('/api/webhooks/fonnte/salah/status', ['id' => '1', 'state' => 'read'])->assertNotFound();
        $this->postJson('/api/webhooks/fonnte/salah/device', ['device' => '62', 'status' => 'disconnect'])->assertNotFound();

        config(['notifications.whatsapp.fonnte.webhook_secret' => '']);
        $this->postJson('/api/webhooks/fonnte/'.self::SECRET.'/status', ['id' => '1', 'state' => 'read'])->assertNotFound();
        $this->postJson('/api/webhooks/fonnte/x/device', ['status' => 'connect'])->assertNotFound();
    }

    public function test_status_webhook_advances_delivery_and_ignores_unknown_values(): void
    {
        Http::fake([self::SEND_URL => Http::response(self::success())]);
        $d = $this->publishWa();
        $url = '/api/webhooks/fonnte/'.self::SECRET.'/status';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        // nilai tak dikenal → 200, tidak ada perubahan, payload mentah tetap dicatat
        $this->postJson($url, ['device' => '6282227097005', 'id' => '80367170', 'stateid' => 'abc', 'status' => 'pending', 'state' => 'misteri'])
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame('sent', $d->refresh()->status);
        $this->assertTrue(collect($logged)->contains(fn ($l) => str_contains($l, '[fonnte:webhook-status]') && str_contains($l, 'misteri')));

        $this->postJson($url, ['device' => '6282227097005', 'id' => '80367170', 'stateid' => 'abc', 'status' => 'sent', 'state' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $d->refresh()->status);
        $this->assertNotNull($d->delivered_at);

        $this->postJson($url, ['id' => '80367170', 'state' => 'read'])->assertOk();
        $this->postJson($url, ['id' => '80367170', 'state' => 'delivered'])->assertOk();   // status tidak mundur
        $this->assertSame('read', $d->refresh()->status);

        // ID tak dikenal / tanpa ID tetap 200
        $this->postJson($url, ['id' => 'tidak-ada', 'state' => 'read'])->assertOk();
        $this->postJson($url, [])->assertOk();
    }

    // ------------------------------------------------------------------ webhook status device

    public function test_device_disconnect_alerts_staff_once_per_hour(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $url = '/api/webhooks/fonnte/'.self::SECRET.'/device';
        $payload = ['device' => '6282227097005', 'status' => 'disconnect', 'timestamp' => 1763280000, 'reason' => 'logout dari HP'];

        $this->postJson($url, $payload)->assertOk()->assertExactJson(['ok' => true]);
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, ['device' => '6282227097005', 'status' => 'connect', 'timestamp' => 1763280100])->assertOk();

        $this->assertSame(1, $admin->notifications()->count());
        $bell = $admin->notifications()->sole();
        $this->assertStringContainsString('6282227097005', $bell->data['title']);
        $this->assertStringContainsString('logout dari HP', $bell->data['body']);
        $this->assertSame(0, $student->notifications()->count());

        Carbon::setTestNow(now()->addMinutes(61));
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(2, $admin->notifications()->count());
    }

    // ------------------------------------------------------------------ token tidak bocor

    public function test_token_never_appears_in_logs_or_errors(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });
        Http::fake([self::SEND_URL => Http::sequence()
            ->push(['reason' => 'device error '.self::TOKEN, 'status' => false])   // penyedia menggemakan token
            ->pushFailedConnection('cURL error 7: '.self::TOKEN)
            ->push(self::success())
            ->push(['reason' => 'token invalid', 'Status' => false])]);

        $first = $this->publishWa();
        $second = $this->publishWa();
        $this->assertSame('Fonnte: device error [token]', $first->last_error);
        $this->dispatchAt(1);
        $this->postJson('/api/webhooks/fonnte/'.self::SECRET.'/status', ['id' => '80367170', 'state' => 'read'])->assertOk();

        $this->assertSame('read', $first->refresh()->status);
        $this->assertSame('failed', $second->refresh()->status);
        $haystack = implode("\n", $logged)
            .NotificationDelivery::query()->pluck('last_error')->implode("\n")
            .NotificationLog::query()->pluck('error')->implode("\n");
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::TOKEN, $haystack);
    }

    // ------------------------------------------------------------------ admin: widget & kirim WA uji

    public function test_status_widget_shows_device_profile_and_survives_api_errors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        Livewire::withoutLazyLoading();
        Http::fake(['https://api.fonnte.com/device' => Http::sequence()
            ->push(['device' => '6282227097005', 'device_status' => 'connect', 'expired' => '18 November 2029', 'messages' => 16785,
                'name' => 'Fonnte admin', 'package' => 'Reguler', 'quota' => '78', 'status' => true])
            ->push('Bad Gateway', 502)]);

        $this->actingAs($admin);
        $this->assertTrue(WhatsAppStatusWidget::canView());
        Livewire::test(WhatsAppStatusWidget::class)
            ->assertSee('Tersambung')->assertSee('6282227097005')->assertSee('Reguler')->assertSee('78')->assertSee('18 November 2029');
        Livewire::test(WhatsAppStatusWidget::class)->assertSee('Tersambung');   // dari cache 5 menit
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->header('Authorization') === [self::TOKEN]);

        Carbon::setTestNow(now()->addMinutes(6));
        Livewire::test(WhatsAppStatusWidget::class)->assertOk()->assertSee('Tidak dapat dihubungi');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));
        $this->assertFalse(WhatsAppStatusWidget::canView());
        $this->actingAs($admin);
        config(['notifications.whatsapp.driver' => 'log']);
        $this->assertFalse(WhatsAppStatusWidget::canView());
    }

    public function test_admin_can_send_audited_test_message(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        Http::fake([self::SEND_URL => Http::response(self::success('999'))]);

        Livewire::actingAs($admin)->test(ListNotificationDeliveries::class)
            ->callAction('testWhatsApp', data: ['phone' => '0812', 'message' => 'Halo'])->assertHasActionErrors(['phone']);
        Livewire::actingAs($admin)->test(ListNotificationDeliveries::class)
            ->callAction('testWhatsApp', data: ['phone' => '0812-3456-7890', 'message' => 'Halo uji'])->assertHasNoActionErrors();

        Http::assertSent(fn (HttpRequest $r) => $r['target'] === '6281234567890' && $r['message'] === 'Halo uji');
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.test_sent', 'actor_id' => $admin->id]);
    }
}
