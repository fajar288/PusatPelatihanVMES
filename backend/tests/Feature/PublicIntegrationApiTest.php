<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\ApiClients\Pages\CreateApiClient;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\RegistrationService;
use App\Services\WebhookService;
use App\Support\PublicApiSpec;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** Phase 5 — API Publik v1 (/api/public/v1): API key, scope, rate limit, log, katalog, pendaftaran, verifikasi, webhook, OpenAPI, admin. */
class PublicIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/public/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 09:00:00'); // CCNA-2611 (17–21 Nov) masih dibuka
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
    }

    /** @return array{0: ApiClient, 1: string} */
    private function client(array $attrs = []): array
    {
        $client = new ApiClient($attrs + ['name' => 'Portal HR Contoh', 'organization' => 'PT Contoh', 'scopes' => array_keys(ApiClient::SCOPES), 'rate_limit' => 60]);
        $key = $client->generateKey();
        $client->save();

        return [$client, $key];
    }

    private function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Budi Santoso', 'email' => 'budi@contoh.co.id',
            'phone' => '081234567890', 'consent' => true, 'external_ref' => 'HR-001',
        ];
    }

    public function test_authentication_scope_ip_and_logging(): void
    {
        $this->getJson(self::BASE.'/')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson(self::BASE.'/', ['X-Api-Key' => 'ppti_salah_123'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        [$client, $key] = $this->client(['scopes' => ['catalog.read']]);
        $this->assertSame(64, strlen($client->key_hash));
        $this->assertStringStartsWith($client->key_prefix.'_', $key);
        $this->assertNotSame($key, $client->key_hash);

        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertJsonPath('data.client', 'Portal HR Contoh')
            ->assertJsonPath('data.scopes', ['catalog.read']);
        // Bearer juga diterima
        $this->getJson(self::BASE.'/categories', ['Authorization' => 'Bearer '.$key])->assertOk();
        // scope kurang
        $this->getJson(self::BASE.'/registrations', ['X-Api-Key' => $key])->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $this->getJson(self::BASE.'/certificates/X', ['X-Api-Key' => $key])->assertStatus(403);

        $this->assertSame(4, ApiRequestLog::query()->where('api_client_id', $client->id)->count());
        $log = ApiRequestLog::query()->where('status', 403)->first();
        $this->assertSame('GET', $log->method);
        $this->assertSame('/api/public/v1/registrations', $log->path);
        $this->assertNotNull($client->refresh()->last_used_at);

        $client->update(['allowed_ips' => ['10.0.0.0/8']]);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
        $client->update(['allowed_ips' => ['127.0.0.1']]);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertOk();

        $client->update(['is_active' => false]);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertStatus(403)->assertJsonPath('error.code', 'client_disabled');
        $client->update(['is_active' => true, 'expires_at' => now()->subMinute()]);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertStatus(401)->assertJsonPath('error.code', 'key_expired');

        // key lama tidak berlaku setelah diganti
        $client->update(['expires_at' => null]);
        $new = $client->generateKey();
        $client->save();
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertStatus(401);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $new])->assertOk();
    }

    public function test_rate_limit_per_client(): void
    {
        [, $key] = $this->client(['rate_limit' => 2]);
        [, $other] = $this->client(['name' => 'Lain', 'rate_limit' => 2]);
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $key])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
        $this->getJson(self::BASE.'/', ['X-Api-Key' => $other])->assertOk(); // limit per klien
    }

    public function test_catalog_endpoints(): void
    {
        [, $key] = $this->client(['scopes' => ['catalog.read']]);
        $h = ['X-Api-Key' => $key];

        $cats = $this->getJson(self::BASE.'/categories', $h)->assertOk()->json('data');
        $this->assertNotEmpty($cats);
        $this->assertArrayHasKey('programs_count', $cats[0]);

        $res = $this->getJson(self::BASE.'/programs?per_page=5', $h)->assertOk()
            ->assertJsonStructure(['data' => [['slug', 'title', 'category', 'price', 'currency', 'url']], 'links', 'meta' => ['total', 'per_page', 'current_page']]);
        $this->assertSame(5, $res->json('meta.per_page'));
        $this->assertGreaterThan(5, $res->json('meta.total'));

        $q = $this->getJson(self::BASE.'/programs?q=ccna', $h)->assertOk()->json('data');
        $this->assertContains('cisco-ccna', array_column($q, 'slug'));

        $detail = $this->getJson(self::BASE.'/programs/cisco-ccna', $h)->assertOk()->json('data');
        $this->assertSame('IDR', $detail['currency']);
        $this->assertIsArray($detail['curriculum']);
        $this->assertContains('CCNA-2611', array_column($detail['upcoming_schedules'], 'code'));
        $this->assertStringEndsWith('/kursus/cisco-ccna', $detail['url']);

        $this->getJson(self::BASE.'/programs/tidak-ada', $h)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson(self::BASE.'/programs?mode=salah', $h)->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['mode']]]);

        $schedules = $this->getJson(self::BASE.'/schedules?program=cisco-ccna&open=1', $h)->assertOk()->json('data');
        $this->assertSame('CCNA-2611', $schedules[0]['code']); // urut tanggal mulai
        $this->assertSame(['cisco-ccna'], array_values(array_unique(array_column(array_column($schedules, 'program'), 'slug'))));
        $this->assertTrue($schedules[0]['registration_open']);
        $this->assertSame(9, $schedules[0]['seats_remaining']);
        $this->assertSame('08:30', $schedules[0]['start_time']);

        $this->getJson(self::BASE.'/schedules/CCNA-2611', $h)->assertOk()->assertJsonPath('data.program.slug', 'cisco-ccna');
        TrainingSchedule::query()->where('code', 'CCNA-2611')->update(['status' => 'draft']);
        $this->getJson(self::BASE.'/schedules/CCNA-2611', $h)->assertStatus(404);
        $this->getJson(self::BASE.'/schedules?from=2026-11-20&to=2026-11-30', $h)->assertOk()
            ->assertJsonMissing(['code' => 'CCNA-2611']);
    }

    public function test_registrations_are_created_idempotently_and_scoped_to_client(): void
    {
        [$client, $key] = $this->client();
        [, $otherKey] = $this->client(['name' => 'Klien lain']);
        $h = ['X-Api-Key' => $key];

        $res = $this->postJson(self::BASE.'/registrations', $this->registrationPayload(), $h)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.external_ref', 'HR-001')
            ->assertJsonPath('data.schedule.code', 'CCNA-2611')
            ->assertJsonPath('data.total', 7500000);
        $number = $res->json('data.number');
        $reg = Registration::query()->where('registration_number', $number)->first();
        $this->assertSame($client->id, $reg->api_client_id);
        $this->assertSame('api', $reg->source);

        // idempoten: external_ref sama → tidak dobel
        $this->postJson(self::BASE.'/registrations', $this->registrationPayload(['name' => 'Nama Lain']), $h)->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.number', $number);
        $this->assertSame(1, Registration::query()->where('api_client_id', $client->id)->count());

        // kolektif
        $this->postJson(self::BASE.'/registrations', $this->registrationPayload([
            'type' => 'corporate', 'company' => 'PT Contoh', 'participants_count' => 2, 'external_ref' => 'HR-002',
            'participants' => [['name' => 'Peserta A'], ['name' => 'Peserta B', 'email' => 'b@contoh.co.id']],
        ]), $h)->assertCreated()->assertJsonPath('data.participants_count', 2)->assertJsonCount(2, 'data.participants');

        // validasi & aturan jadwal
        $this->postJson(self::BASE.'/registrations', ['schedule' => 'CCNA-2611'], $h)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson(self::BASE.'/registrations', $this->registrationPayload(['type' => 'corporate', 'company' => 'PT X', 'participants_count' => 50, 'external_ref' => 'HR-003']), $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'insufficient_seats');
        TrainingSchedule::query()->where('code', 'CCNA-2611')->update(['status' => 'registration_closed']);
        $this->postJson(self::BASE.'/registrations', $this->registrationPayload(['external_ref' => 'HR-004']), $h)->assertStatus(422)->assertJsonPath('error.code', 'registration_closed');
        TrainingSchedule::query()->where('code', 'CCNA-2611')->update(['status' => 'registration_open', 'registration_deadline' => '2026-11-10']);
        $this->postJson(self::BASE.'/registrations', $this->registrationPayload(['external_ref' => 'HR-005']), $h)->assertStatus(422)->assertJsonPath('error.code', 'registration_closed');

        // baca: hanya milik klien sendiri
        $this->getJson(self::BASE.'/registrations', $h)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::BASE.'/registrations?external_ref=HR-001', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE."/registrations/$number", $h)->assertOk()->assertJsonPath('data.contact.name', 'Budi Santoso');
        $this->getJson(self::BASE."/registrations/$number", ['X-Api-Key' => $otherKey])->assertStatus(404);
        $this->getJson(self::BASE.'/registrations', ['X-Api-Key' => $otherKey])->assertOk()->assertJsonCount(0, 'data');

        // status & tagihan terlihat setelah diproses admin
        app(RegistrationService::class)->transition($reg, RegistrationStatus::WaitingPayment);
        $this->getJson(self::BASE."/registrations/$number", $h)->assertOk()
            ->assertJsonPath('data.status', 'waiting_payment')->assertJsonPath('data.invoice.status', 'unpaid');
    }

    public function test_webhooks_are_signed_and_logged(): void
    {
        Http::fake(['hooks.contoh.test/*' => Http::response(['ok' => true], 200)]);
        [$client, $key] = $this->client([
            'webhook_url' => 'https://hooks.contoh.test/ppti',
            'webhook_events' => ['registration.created', 'registration.status_changed', 'certificate.issued'],
        ]);
        $secret = $client->generateWebhookSecret();
        $client->save();

        $number = $this->postJson(self::BASE.'/registrations', $this->registrationPayload(), ['X-Api-Key' => $key])->assertCreated()->json('data.number');
        $reg = Registration::query()->where('registration_number', $number)->first();
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment);
        $svc->transition($reg->refresh(), RegistrationStatus::PaymentVerification);
        $svc->transition($reg->refresh(), RegistrationStatus::Confirmed);

        $events = WebhookDelivery::query()->orderBy('id')->pluck('event')->all();
        $this->assertSame(['registration.created', 'registration.status_changed', 'registration.status_changed', 'registration.status_changed'], $events);
        $this->assertSame(4, WebhookDelivery::query()->where('status', 'delivered')->count());
        $last = WebhookDelivery::query()->latest('id')->first();
        $this->assertSame('confirmed', $last->payload['data']['status']);
        $this->assertSame('payment_verification', $last->payload['data']['previous_status']);
        $this->assertSame('HR-001', $last->payload['data']['external_ref']);

        Http::assertSent(function (HttpRequest $r) use ($secret) {
            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $r->header('X-PPTI-Signature')[0] ?? '', $m);

            return $r->url() === 'https://hooks.contoh.test/ppti'
                && $r->header('X-PPTI-Event')[0] === 'registration.created'
                && $m && hash_equals(hash_hmac('sha256', $m[1].'.'.$r->body(), $secret), $m[2]);
        });

        // sertifikat peserta dari pendaftaran klien terbit → certificate.issued; event yang tidak dilanggan tidak dikirim
        $enrollment = Enrollment::query()->where('registration_id', $reg->id)->first();
        $cert = Certificate::query()->create([
            'number' => 'PPTI-PST-202611-0001', 'verification_code' => 'ABCD1234', 'enrollment_id' => $enrollment->id,
            'recipient_name' => 'Budi Santoso', 'program_title' => 'Cisco CCNA', 'class_code' => 'CCNA-2611', 'status' => 'draft', 'type' => 'participation',
        ]);
        $cert->update(['status' => 'issued', 'issued_at' => today()]);
        $issued = WebhookDelivery::query()->where('event', 'certificate.issued')->first();
        $this->assertNotNull($issued);
        $this->assertSame('PPTI-PST-202611-0001', $issued->payload['data']['number']);
        $this->assertSame($number, $issued->payload['data']['registration_number']);
        $cert->update(['status' => 'revoked', 'revoked_at' => today()]);
        $this->assertSame(0, WebhookDelivery::query()->where('event', 'certificate.revoked')->count());

        // verifikasi sertifikat via API
        $this->getJson(self::BASE.'/certificates/PPTI-PST-202611-0001?code=abcd1234', ['X-Api-Key' => $key])->assertOk()
            ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.valid', false)->assertJsonPath('data.code_matches', true)
            ->assertJsonPath('data.recipient_name', 'Budi Santoso');
        $this->getJson(self::BASE.'/certificates/TIDAK-ADA', ['X-Api-Key' => $key])->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_failed_webhook_is_recorded_and_can_be_retried(): void
    {
        Http::fakeSequence('hooks.contoh.test/*')->push('down', 503)->push('ok', 200);
        [$client] = $this->client(['webhook_url' => 'https://hooks.contoh.test/ppti', 'webhook_events' => ['registration.created']]);
        $client->generateWebhookSecret();
        $client->save();

        $d = app(WebhookService::class)->emit($client, 'registration.created', ['number' => 'REG-X']);
        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame(503, $d->response_status);
        $this->assertTrue(app(WebhookService::class)->attempt($d));
        $this->assertSame('delivered', $d->refresh()->status);
        $this->assertSame(2, $d->attempts);

        // tanpa langganan event → tidak dibuat
        $this->assertNull(app(WebhookService::class)->emit($client, 'certificate.issued', []));
    }

    public function test_openapi_spec_documents_every_public_route(): void
    {
        $spec = $this->getJson(self::BASE.'/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();
        $this->assertSame($spec, json_decode(json_encode(PublicApiSpec::toArray()), true));
        $documented = collect($spec['paths'])->flatMap(fn ($ops, $path) => collect(array_keys($ops))->map(fn ($m) => strtoupper($m).' '.$path))->all();

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/public/v1'));
        $this->assertGreaterThanOrEqual(11, $routes->count());
        foreach ($routes as $r) {
            $path = '/'.ltrim(substr($r->uri(), strlen('api/public/v1')), '/');
            $method = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $this->assertContains("$method $path", $documented, "Route $method $path belum ada di PublicApiSpec");
        }
        $this->assertArrayHasKey('certificate.issued', $spec['webhooks']);
        $this->get('/docs/api')->assertOk()->assertSee('redoc', false)->assertSee('/api/public/v1/openapi.json', false);
    }

    public function test_admin_manages_api_clients(): void
    {
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $this->actingAs($finance)->get('/admin/api-clients')->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->actingAs($admin)->get('/admin/api-clients')->assertOk();
        $this->actingAs($admin)->get('/admin/api-clients/create')->assertOk();

        Livewire::actingAs($admin)->test(CreateApiClient::class)
            ->fillForm([
                'name' => 'Agregator Kursus', 'organization' => 'PT Agregator', 'scopes' => ['catalog.read'], 'rate_limit' => 120, 'is_active' => true,
                'allowed_ips' => ['203.0.113.0/24'], 'webhook_url' => 'https://hooks.agregator.test/ppti', 'webhook_events' => ['registration.created'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = ApiClient::query()->where('name', 'Agregator Kursus')->firstOrFail();
        $this->assertSame(['catalog.read'], $client->scopes);
        $this->assertSame(120, $client->rate_limit);
        $this->assertSame($admin->id, $client->created_by);
        $this->assertNotEmpty($client->key_hash);
        $this->assertStringStartsWith('whsec_', $client->webhook_secret);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_client.created']);

        Livewire::actingAs($admin)->test(CreateApiClient::class)
            ->fillForm(['name' => 'Salah', 'scopes' => ['catalog.read'], 'rate_limit' => 60, 'allowed_ips' => ['bukan-ip']])
            ->call('create')
            ->assertHasFormErrors(['allowed_ips.0']);

        $this->actingAs($admin)->get("/admin/api-clients/{$client->id}/edit")->assertOk()->assertSee($client->key_prefix);
    }
}
