<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiClients\Pages\CreateApiClient;
use App\Http\Middleware\EnforceIdempotency;
use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\TrainingLogbook;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\SponsorshipService;
use App\Services\TrainingRequestService;
use App\Support\PublicApiSpec;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5E — API enterprise (PRD Roadmap 5 §7, §7.1, §11, §20.21): klien terikat organisasi, scope + peran,
 * isolasi antar perusahaan, Idempotency-Key, endpoint §7, webhook domain baru, OpenAPI, admin.
 */
class EnterpriseApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/public/v1';

    private OrganizationService $orgs;

    private Organization $abc;

    private Organization $xyz;

    private TrainingSchedule $schedule;

    private CorporateUser $hr;

    private CorporateUser $xyzHr;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->orgs = app(OrganizationService::class);
        $this->abc = $this->orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->xyz = $this->orgs->create(['legal_name' => 'PT XYZ Energi']);
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        $this->hr = $this->orgs->addCorporateUser($this->abc, User::factory()->create(['name' => 'Hana HR', 'email' => 'hana@abc.co.id']), 'hr_admin');
        $this->xyzHr = $this->orgs->addCorporateUser($this->xyz, User::factory()->create(['name' => 'Xena HR']), 'hr_admin');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: ApiClient, 1: string} */
    private function client(CorporateUser $cu, ?array $scopes = null, array $attrs = []): array
    {
        $c = new ApiClient($attrs + [
            'name' => 'HRIS '.$cu->organization->code.' '.Str::random(4), 'organization_id' => $cu->organization_id, 'corporate_user_id' => $cu->id,
            'scopes' => $scopes ?? array_keys(ApiClient::allScopes()), 'rate_limit' => 1000,
        ]);
        $key = $c->generateKey();
        if (filled($attrs['webhook_url'] ?? null)) {
            $c->generateWebhookSecret();
        }
        $c->save();

        return [$c, $key];
    }

    private function h(string $key, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Api-Key' => $key, 'Idempotency-Key' => $idempotencyKey]);
    }

    private function member(string $name, string $email, bool $verified = true): Member
    {
        $u = User::factory()->create(['name' => $name, 'email' => $email, 'email_verified_at' => $verified ? now() : null]);

        return app(MemberService::class)->createForUser($u)->load('user');
    }

    private function newRequest(string $key, int $seats = 3): TrainingRequest
    {
        $code = $this->postJson(self::BASE.'/corporate/requests', [
            'program' => $this->schedule->package->slug, 'seats' => $seats, 'delivery' => 'onsite', 'target_date' => '2026-12-01', 'submit' => true,
        ], $this->h($key, (string) Str::uuid()))->assertCreated()->json('data.code');

        return TrainingRequest::query()->where('code', $code)->firstOrFail();
    }

    private function toScheduled(TrainingRequest $r, string $key): TrainingRequest
    {
        $svc = app(TrainingRequestService::class);
        $svc->quote($r, $this->admin, 7000000, 1000000, '2026-11-30');
        $this->postJson(self::BASE."/corporate/requests/{$r->code}/accept-quote", [], $this->h($key, (string) Str::uuid()))->assertOk()->assertJsonPath('data.status', 'approved');
        $svc->markPaid($r->refresh(), $this->admin, 'TRF-1');
        $svc->schedule($r->refresh(), $this->schedule, $this->admin);

        return $r->refresh();
    }

    private function assign(string $key, TrainingRequest $r, array $person, ?int $seat = null)
    {
        return $this->postJson(self::BASE."/corporate/requests/{$r->code}/participants", $person + array_filter(['seat' => $seat]), $this->h($key, (string) Str::uuid()));
    }

    // ---------------------------------------------------------------------

    public function test_binding_scope_role_and_revocation(): void
    {
        // klien mitra (tanpa organisasi) tidak bisa memakai endpoint enterprise
        $partner = new ApiClient(['name' => 'Agregator', 'scopes' => ['catalog.read']]);
        $partnerKey = $partner->generateKey();
        $partner->save();
        $this->getJson(self::BASE.'/enrollments', $this->h($partnerKey))->assertStatus(403)->assertJsonPath('error.code', 'organization_required');
        $this->getJson(self::BASE.'/', $this->h($partnerKey))->assertOk()->assertJsonPath('data.enterprise', null);

        // aturan model: scope enterprise wajib organisasi + pengguna perusahaan dari organisasi yang sama
        $bad = new ApiClient(['name' => 'Salah', 'scopes' => ['enrollments.read']]);
        $bad->generateKey();
        $this->assertThrows(fn () => $bad->save(), DomainException::class);
        $bad->forceFill(['organization_id' => $this->abc->id, 'corporate_user_id' => $this->xyzHr->id]);
        $this->assertThrows(fn () => $bad->save(), DomainException::class);

        [$client, $key] = $this->client($this->hr);
        $this->getJson(self::BASE.'/', $this->h($key))->assertOk()->assertHeader('API-Version', '1')
            ->assertJsonPath('data.enterprise.organization_code', $this->abc->code)->assertJsonPath('data.enterprise.acting_role', 'hr_admin');
        $this->getJson(self::BASE.'/corporate/organization', $this->h($key))->assertOk()
            ->assertJsonPath('data.code', $this->abc->code)->assertJsonPath('data.acting_as.role', 'hr_admin');

        // peran membatasi scope: HR tidak punya view_finance
        $this->getJson(self::BASE.'/payments', $this->h($key))->assertStatus(403)->assertJsonPath('error.code', 'insufficient_role');
        // finance perusahaan: keuangan boleh, data peserta tidak
        $fin = $this->orgs->addCorporateUser($this->abc, User::factory()->create(), 'finance');
        [, $finKey] = $this->client($fin);
        $this->getJson(self::BASE.'/payments', $this->h($finKey))->assertOk();
        $this->getJson(self::BASE.'/enrollments', $this->h($finKey))->assertStatus(403)->assertJsonPath('error.code', 'insufficient_role');
        $this->getJson(self::BASE.'/progress', $this->h($finKey))->assertStatus(403);

        // scope tidak diberikan
        [, $narrow] = $this->client($this->hr, ['organization.read']);
        $this->getJson(self::BASE.'/enrollments', $this->h($narrow))->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');

        // pengguna perusahaan yang diwakili dicabut (mis. resign) → klien langsung terputus (§20.21 token dapat dicabut)
        $this->orgs->disableCorporateUser($this->hr, 'Uji pencabutan');
        $this->getJson(self::BASE.'/corporate/organization', $this->h($key))->assertStatus(403)->assertJsonPath('error.code', 'acting_user_inactive');
        // organisasi ditangguhkan
        $this->xyz->update(['status' => 'suspended']);
        [, $xyzKey] = $this->client($this->xyzHr);
        $this->getJson(self::BASE.'/corporate/organization', $this->h($xyzKey))->assertStatus(403)->assertJsonPath('error.code', 'organization_inactive');
        // klien dinonaktifkan admin
        $client->update(['is_active' => false]);
        $this->getJson(self::BASE.'/corporate/organization', $this->h($key))->assertStatus(403)->assertJsonPath('error.code', 'client_disabled');
    }

    public function test_requests_with_idempotency_and_organization_isolation(): void
    {
        [$client, $key] = $this->client($this->hr);
        [, $xyzKey] = $this->client($this->xyzHr);
        $members = Member::count();
        $payload = ['program' => $this->schedule->package->slug, 'seats' => 3, 'delivery' => 'onsite', 'target_date' => '2026-12-01'];

        $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($key))->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($key, 'bukan key valid!'))->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_invalid');

        $first = $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($key, 'req-001'))->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.seats', 3)->assertJsonCount(3, 'data.seat_list')
            ->assertJsonPath('data.seat_list.0.status', 'reserved');
        $code = $first->json('data.code');
        $this->assertSame($members, Member::count(), 'kursi tidak membuat member (§20.22)');

        // diulang (mis. timeout jaringan) → respons yang sama diputar ulang, tidak ada request kedua
        $replay = $this->postJson(self::BASE.'/corporate/requests', array_reverse($payload, true), $this->h($key, 'req-001'))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, TrainingRequest::count());
        // key sama, isi berbeda
        $this->postJson(self::BASE.'/corporate/requests', ['seats' => 5] + $payload, $this->h($key, 'req-001'))->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
        // permintaan pertama masih diproses
        $busy = ['seats' => 4] + $payload;
        ApiIdempotencyKey::query()->create(['api_client_id' => $client->id, 'key' => 'req-busy', 'method' => 'POST', 'status' => 'processing', 'expires_at' => now()->addDay(),
            'request_hash' => EnforceIdempotency::fingerprint(Request::create(self::BASE.'/corporate/requests', 'POST', [], [], [], [], json_encode($busy)))]);
        $this->postJson(self::BASE.'/corporate/requests', $busy, $this->h($key, 'req-busy'))->assertStatus(409)->assertJsonPath('error.code', 'idempotency_in_progress');
        // galat tidak disimpan: key boleh dipakai lagi setelah data diperbaiki
        $this->postJson(self::BASE.'/corporate/requests', ['program' => 'tidak-ada'] + $payload, $this->h($key, 'req-002'))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($key, 'req-002'))->assertCreated();
        // key kedaluwarsa dianggap baru
        ApiIdempotencyKey::query()->where('key', 'req-001')->update(['expires_at' => now()->subMinute()]);
        $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($key, 'req-001'))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(3, TrainingRequest::count());
        // key per klien: klien lain boleh memakai nilai yang sama
        $this->postJson(self::BASE.'/corporate/requests', $payload, $this->h($xyzKey, 'req-002'))->assertCreated();

        // audit mencatat aktor pengguna perusahaan + klien API
        $log = AuditLog::query()->where('action', 'training_request.created')->where('auditable_id', TrainingRequest::where('code', $code)->value('id'))->firstOrFail();
        $this->assertSame($client->id, $log->api_client_id);
        $this->assertSame($this->hr->user_id, $log->actor_id);

        // daftar & detail hanya organisasi sendiri; kode organisasi lain → 404 (§20.21)
        $this->getJson(self::BASE.'/corporate/requests', $this->h($key))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::BASE.'/corporate/requests?status=draft', $this->h($key))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(self::BASE."/corporate/requests/$code", $this->h($xyzKey))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->postJson(self::BASE."/corporate/requests/$code/cancel", [], $this->h($xyzKey, 'x-cancel'))->assertNotFound();

        // transisi: kirim → penawaran VMES → terima penawaran (organisasi tanpa persetujuan → approved, invoice dibuat)
        $this->postJson(self::BASE."/corporate/requests/$code/submit", [], $this->h($key, 'sub-1'))->assertOk()->assertJsonPath('data.status', 'submitted');
        app(TrainingRequestService::class)->quote(TrainingRequest::where('code', $code)->first(), $this->admin, 5000000);
        $this->getJson(self::BASE."/corporate/requests/$code", $this->h($key))->assertJsonPath('data.status', 'quoted')->assertJsonPath('data.quote.total', 15000000);
        $this->postJson(self::BASE."/corporate/requests/$code/accept-quote", [], $this->h($key, 'acc-1'))->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.invoice.status', 'pending');
        // menerima penawaran dua kali dengan key berbeda ditolak aturan bisnis, bukan diproses ganda
        $this->postJson(self::BASE."/corporate/requests/$code/accept-quote", [], $this->h($key, 'acc-2'))->assertStatus(422);
        $this->postJson(self::BASE."/corporate/requests/$code/hapus", [], $this->h($key, 'x'))->assertNotFound();
    }

    public function test_assign_reuses_identity_and_release_changes_only_one_enrollment(): void
    {
        [, $key] = $this->client($this->hr);
        [, $xyzKey] = $this->client($this->xyzHr);
        $budi = $this->member('Budi Santoso', 'budi@example.com');
        $siti = $this->member('Siti', 'siti@example.com');
        $users = User::count();
        $r = $this->toScheduled($this->newRequest($key, 4), $key);

        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'BUDI@example.com', 'employee_id' => 'ABC-1'])->assertCreated()
            ->assertJsonPath('data.result', 'assigned')->assertJsonPath('data.seat.no', 1)->assertJsonPath('data.seat.status', 'enrolled');
        $this->assertSame($users, User::count(), 'member existing dipakai ulang (§20.22)');
        $eBudi = Enrollment::query()->where('member_id', $budi->id)->firstOrFail();
        $this->assertSame(Enrollment::TYPE_CORPORATE, $eBudi->type);
        $this->assertSame($this->abc->id, $eBudi->sponsor_organization_id);
        // duplikat di batch yang sama ditolak
        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'budi@example.com'])->assertStatus(422);
        // calon peserta tanpa akun → undangan (tidak membuat akun)
        $this->assign($key, $r, ['name' => 'Tamu', 'email' => 'tamu@example.com'])->assertCreated()->assertJsonPath('data.result', 'invited');
        $this->assertSame($users, User::count());
        // karyawan aktif dipilih lewat kode employment
        $emp = app(EmploymentService::class)->start($siti, $this->abc, ['employee_id' => 'ABC-2']);
        $this->assign($key, $r, ['employment' => $emp->code], 4)->assertCreated()->assertJsonPath('data.seat.no', 4);
        $eSiti = Enrollment::query()->where('member_id', $siti->id)->firstOrFail();

        // enrollment self-funded Budi & enrollment sponsor organisasi lain tidak pernah terlihat (R2, R3, §20.8)
        $other = TrainingSchedule::query()->where('id', '!=', $this->schedule->id)->firstOrFail();
        $self = Enrollment::query()->create(['schedule_id' => $other->id, 'member_id' => $budi->id, 'type' => Enrollment::TYPE_SELF, 'participant_name' => 'Budi', 'enrolled_at' => now()]);
        $xyzE = Enrollment::query()->create(['schedule_id' => $other->id, 'member_id' => $siti->id, 'type' => Enrollment::TYPE_CORPORATE, 'sponsor_organization_id' => $this->xyz->id, 'participant_name' => 'Siti', 'enrolled_at' => now()]);

        $list = $this->getJson(self::BASE.'/enrollments', $this->h($key))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$eBudi->id, $eSiti->id], array_column($list->json('data'), 'id'));
        $this->assertStringNotContainsString('budi@example.com', $list->getContent(), 'email peserta tidak dibuka (§20.11)');
        $this->getJson(self::BASE.'/enrollments?employee_id=ABC-1', $this->h($key))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.participant.employee_id', 'ABC-1')->assertJsonPath('data.0.request.code', $r->code)->assertJsonPath('data.0.request.seat_no', 1);
        $this->getJson(self::BASE."/enrollments/{$self->id}", $this->h($key))->assertNotFound();
        $this->getJson(self::BASE."/enrollments/{$xyzE->id}", $this->h($key))->assertNotFound();
        $this->getJson(self::BASE."/enrollments/{$eBudi->id}", $this->h($xyzKey))->assertNotFound();
        $this->getJson(self::BASE.'/enrollments', $this->h($xyzKey))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $xyzE->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.participants_viewed']);

        // batalkan satu peserta → hanya enrollment kursi itu
        $this->postJson(self::BASE."/corporate/requests/{$r->code}/seats/1/release", ['reason' => 'Dinas luar'], $this->h($key, 'rel-1'))->assertOk()
            ->assertJsonPath('data.dropped_enrollment_id', $eBudi->id)->assertJsonPath('data.seat.status', 'reserved');
        $this->assertSame('dropped', $eBudi->fresh()->status);
        $this->assertSame('enrolled', $eSiti->fresh()->status);
        $this->assertSame('enrolled', $self->fresh()->status);
        $this->postJson(self::BASE."/corporate/requests/{$r->code}/seats/1/release", [], $this->h($key, 'rel-2'))->assertStatus(422);
        $this->postJson(self::BASE."/corporate/requests/{$r->code}/seats/99/release", ['reason' => 'x'], $this->h($key, 'rel-3'))->assertNotFound();
    }

    public function test_progress_attendance_assessments_hide_private_notes_and_follow_exit_policy(): void
    {
        [, $key] = $this->client($this->hr);
        $budi = $this->member('Budi', 'budi@example.com');
        $r = $this->toScheduled($this->newRequest($key), $key);
        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'budi@example.com', 'employee_id' => 'ABC-9'])->assertCreated();
        $e = Enrollment::query()->where('member_id', $budi->id)->firstOrFail();
        $session = ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => 99, 'date' => '2026-11-09', 'topic' => 'Pengantar']);
        $e->attendances()->create(['session_id' => $session->id, 'status' => 'present', 'checked_at' => now(), 'note' => 'RAHASIA-ABSEN']);
        $lb = TrainingLogbook::query()->create(['schedule_id' => $this->schedule->id, 'date' => '2026-11-09', 'topic' => 'Routing dasar', 'status' => 'validated', 'notes' => 'RAHASIA-INTERNAL', 'observations' => 'RAHASIA-KELAS']);
        $lb->entries()->create(['enrollment_id' => $e->id, 'practice_status' => 'completed', 'observation' => 'RAHASIA-PESERTA']);

        $p = $this->getJson(self::BASE.'/progress', $this->h($key))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.enrollment_id', $e->id)->assertJsonPath('data.0.logbook.0.topic', 'Routing dasar')->assertJsonPath('data.0.practice.completed', 1);
        $a = $this->getJson(self::BASE.'/attendance', $this->h($key))->assertOk()->assertJsonPath('data.0.attendance.present', 1)
            ->assertJsonPath('data.0.attendance.sessions.0.status', 'present');
        $s = $this->getJson(self::BASE.'/assessments', $this->h($key))->assertOk()->assertJsonPath('data.0.competency', null);
        foreach ([$p, $a, $s] as $res) {
            $this->assertStringNotContainsString('RAHASIA', $res->getContent());
            $this->assertStringNotContainsString('budi@example.com', $res->getContent());
        }
        $this->getJson(self::BASE.'/enrollments', $this->h($key))->assertJsonPath('data.0.progress_visible', true)->assertJsonPath('data.0.attendance_percent', 100);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.progress_viewed']);

        // resign: employment INACTIVE → detail progres tertutup (exit_access default summary), daftar & agregat tetap
        app(EmploymentService::class)->end(EmploymentRelationship::query()->where('member_id', $budi->id)->firstOrFail());
        $this->getJson(self::BASE.'/progress', $this->h($key))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/enrollments', $this->h($key))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.progress_visible', false)->assertJsonPath('data.0.attendance_percent', null)->assertJsonPath('data.0.participant.employment_status', 'inactive');
        $this->getJson(self::BASE.'/analytics', $this->h($key))->assertOk()->assertJsonPath('data.kpi.sponsored_participants', 1);
        // exit_access=none → peserta yang keluar hilang dari daftar
        $this->abc->update(['settings' => ['exit_access' => 'none']]);
        $this->getJson(self::BASE.'/enrollments', $this->h($key))->assertJsonCount(0, 'data');
        $this->getJson(self::BASE."/enrollments/{$e->id}", $this->h($key))->assertNotFound();
        $this->assertSame(Enrollment::TYPE_CORPORATE, $e->fresh()->type, 'histori tidak dihapus (R4)');
    }

    public function test_employee_directory_and_hris_sync_never_create_accounts(): void
    {
        [, $key] = $this->client($this->hr);
        [, $xyzKey] = $this->client($this->xyzHr);
        $budi = $this->member('Budi', 'budi@example.com');
        $this->member('Belum Verifikasi', 'unverified@example.com', verified: false);
        $users = User::count();

        $this->postJson(self::BASE.'/corporate/employees', ['employee_id' => 'ABC-7', 'email' => 'budi@example.com', 'position' => 'Teknisi'], $this->h($key))->assertStatus(400);
        $code = $this->postJson(self::BASE.'/corporate/employees', ['employee_id' => 'ABC-7', 'email' => 'budi@example.com', 'position' => 'Teknisi'], $this->h($key, 'emp-1'))
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.source', 'api')->assertJsonPath('data.member_number', $budi->member_number)->json('data.code');
        $this->postJson(self::BASE.'/corporate/employees', ['employee_id' => 'ABC-7', 'email' => 'budi@example.com'], $this->h($key, 'emp-2'))
            ->assertOk()->assertJsonPath('meta.already_active', true)->assertJsonPath('data.code', $code);
        $this->postJson(self::BASE.'/corporate/employees', ['employee_id' => 'ABC-8', 'email' => 'unverified@example.com'], $this->h($key, 'emp-3'))
            ->assertStatus(422)->assertJsonPath('error.code', 'identity_unresolved')->assertJsonPath('error.outcome', 'confirm');
        $this->postJson(self::BASE.'/corporate/employees', ['employee_id' => 'ABC-9', 'email' => 'baru@example.com'], $this->h($key, 'emp-4'))
            ->assertStatus(422)->assertJsonPath('error.outcome', 'create_new');
        $this->assertSame($users, User::count(), 'API tidak pernah membuat akun (§20.5)');

        $this->getJson(self::BASE.'/corporate/employees', $this->h($key))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', 'ABC-7');
        $this->getJson(self::BASE."/corporate/employees/$code", $this->h($xyzKey))->assertNotFound();
        $this->postJson(self::BASE."/corporate/employees/$code/end", ['reason' => 'resigned'], $this->h($xyzKey, 'end-x'))->assertNotFound();

        $this->postJson(self::BASE."/corporate/employees/$code/end", ['reason' => 'resigned', 'end_date' => '2026-11-10'], $this->h($key, 'end-1'))->assertOk()
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.end_reason', 'resigned');
        $this->assertNotNull($budi->fresh(), 'member tetap ada (R4)');
        $this->getJson(self::BASE.'/corporate/employees', $this->h($key))->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/corporate/employees?status=inactive', $this->h($key))->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'employment.ended', 'actor_id' => $this->hr->user_id]);
    }

    public function test_payments_notifications_certificates_and_analytics_are_scoped(): void
    {
        [, $key] = $this->client($this->hr);
        $fin = $this->orgs->addCorporateUser($this->abc, User::factory()->create(), 'finance');
        [, $finKey] = $this->client($fin);
        $xyzFin = $this->orgs->addCorporateUser($this->xyz, User::factory()->create(), 'finance');
        [, $xyzFinKey] = $this->client($xyzFin);
        $budi = $this->member('Budi', 'budi@example.com');
        $r = $this->toScheduled($this->newRequest($key, 2), $key);
        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'budi@example.com'])->assertCreated();
        $invoice = $r->invoices()->firstOrFail();

        $this->getJson(self::BASE.'/payments', $this->h($finKey))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $invoice->invoice_number)->assertJsonPath('data.0.source.code', $r->code);
        $this->getJson(self::BASE."/payments/{$invoice->invoice_number}", $this->h($finKey))->assertOk()->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.transactions', []);
        $this->getJson(self::BASE."/payments/{$invoice->invoice_number}", $this->h($xyzFinKey))->assertNotFound();
        $this->getJson(self::BASE.'/payments', $this->h($xyzFinKey))->assertJsonCount(0, 'data');

        // notifikasi: hanya pengiriman ke pengguna perusahaan organisasi ini
        $event = NotificationEvent::query()->create(['key' => 'request.status_changed']);
        foreach ([$this->abc, $this->xyz] as $org) {
            NotificationDelivery::query()->create(['event_id' => $event->id, 'audience' => 'corporate', 'channel' => 'mail', 'recipient_key' => 'corp:'.$org->id,
                'recipient' => 'x@example.com', 'recipient_name' => 'PIC '.$org->code, 'organization_id' => $org->id, 'content' => ['title' => 'Request '.$org->code], 'status' => 'sent']);
        }
        $notes = $this->getJson(self::BASE.'/notifications?per_page=100', $this->h($key))->assertOk();
        $this->assertSame(NotificationDelivery::query()->where('audience', 'corporate')->where('organization_id', $this->abc->id)->count(), count($notes->json('data')));
        $this->assertContains('PIC '.$this->abc->code, array_column($notes->json('data'), 'recipient_name'));
        $this->assertStringNotContainsString('PIC '.$this->xyz->code, $notes->getContent());
        $this->getJson(self::BASE.'/notifications?status=sent&channel=mail&event=request.status_changed', $this->h($key))->assertJsonPath('data.0.title', 'Request '.$this->abc->code);
        $this->assertSame(0, NotificationDelivery::query()->where('audience', 'corporate')->where('organization_id', $this->abc->id)->where('status', 'failed')->count());
        $this->getJson(self::BASE.'/notifications?status=failed', $this->h($key))->assertJsonCount(0, 'data');

        // sertifikat: status dari enrollment bersponsor, nomor hanya bila terbit
        $e = Enrollment::query()->where('member_id', $budi->id)->firstOrFail();
        $cert = Certificate::query()->create(['number' => 'PPTI-T-0001', 'verification_code' => 'ABCD1234', 'enrollment_id' => $e->id, 'type' => 'participation',
            'recipient_name' => 'Budi', 'program_title' => 'CCNA', 'status' => 'pending_validation']);
        $this->getJson(self::BASE.'/certificates', $this->h($key))->assertOk()->assertJsonPath('data.0.status', 'pending_validation')->assertJsonPath('data.0.number', null);
        $cert->update(['status' => 'issued', 'issued_at' => '2026-11-21']);
        $this->getJson(self::BASE.'/certificates?status=issued', $this->h($key))->assertJsonPath('data.0.number', 'PPTI-T-0001')->assertJsonPath('data.0.enrollment_id', $e->id);
        $this->getJson(self::BASE.'/certificates', $this->h($xyzFinKey))->assertJsonCount(0, 'data');

        $this->getJson(self::BASE.'/analytics', $this->h($key))->assertOk()
            ->assertJsonPath('data.kpi.sponsored_participants', 1)->assertJsonPath('data.kpi.seats_bought', 2)->assertJsonPath('data.kpi.seats_filled', 1)
            ->assertJsonPath('data.kpi.certificates_issued', 1)->assertJsonPath('data.scope.funding', 'corporate_sponsored');
        $this->getJson(self::BASE.'/analytics', $this->h($xyzFinKey))->assertJsonPath('data.kpi.sponsored_participants', 0);
    }

    public function test_enterprise_webhooks_reach_only_entitled_clients_of_the_organization(): void
    {
        $events = array_keys(ApiClient::allWebhookEvents());
        [$abcClient, $key] = $this->client($this->hr, attrs: ['webhook_url' => 'https://hris.abc.test/hook', 'webhook_events' => $events]);
        [$xyzClient] = $this->client($this->xyzHr, attrs: ['webhook_url' => 'https://hris.xyz.test/hook', 'webhook_events' => $events]);
        // finance perusahaan menerima event invoice; klien HR (tanpa view_finance) tidak
        [$finClient] = $this->client($this->orgs->addCorporateUser($this->abc, User::factory()->create(), 'finance'), attrs: ['webhook_url' => 'https://fin.abc.test/hook', 'webhook_events' => $events]);
        // klien ABC tanpa scope payments.read tidak menerima event invoice walau berlangganan
        [$noFinance] = $this->client($this->hr, ['corporate.requests.read'], ['webhook_url' => 'https://lain.abc.test/hook', 'webhook_events' => $events]);
        $budi = $this->member('Budi', 'budi@example.com');

        $r = $this->toScheduled($this->newRequest($key), $key);
        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'budi@example.com', 'employee_id' => 'ABC-1'])->assertCreated();
        $e = Enrollment::query()->where('member_id', $budi->id)->firstOrFail();
        $e->update(['status' => 'attending']);

        $abc = WebhookDelivery::query()->where('api_client_id', $abcClient->id)->pluck('event')->all();
        foreach (['training_request.status_changed', 'enrollment.created', 'enrollment.status_changed', 'employee.status_changed'] as $ev) {
            $this->assertContains($ev, $abc, "ABC menerima $ev");
        }
        $this->assertNotContains('invoice.status_changed', $abc, 'peran HR tanpa view_finance tidak menerima event invoice');
        $fin = WebhookDelivery::query()->where('api_client_id', $finClient->id)->get();
        $this->assertSame(['invoice.status_changed', 'training_request.status_changed'], $fin->pluck('event')->unique()->sort()->values()->all(), 'finance: request + invoice, tanpa data peserta');
        $this->assertNull($fin->firstWhere('event', 'invoice.status_changed')->payload['data']['previous_status']);
        $this->assertSame(0, WebhookDelivery::query()->where('api_client_id', $xyzClient->id)->count(), 'organisasi lain tidak menerima apa pun');
        $noFin = WebhookDelivery::query()->where('api_client_id', $noFinance->id)->pluck('event')->unique()->values()->all();
        $this->assertSame(['training_request.status_changed'], $noFin);

        $created = WebhookDelivery::query()->where('api_client_id', $abcClient->id)->where('event', 'enrollment.created')->firstOrFail();
        $this->assertSame($e->id, $created->payload['data']['id']);
        $this->assertSame('ABC-1', $created->payload['data']['participant']['employee_id']);
        $this->assertStringNotContainsString('budi@example.com', json_encode($created->payload));
        $changed = WebhookDelivery::query()->where('api_client_id', $abcClient->id)->where('event', 'enrollment.status_changed')->firstOrFail();
        $this->assertSame(['enrolled', 'attending'], [$changed->payload['data']['previous_status'], $changed->payload['data']['status']]);
        $this->assertSame('delivered', $changed->status);
        Http::assertSent(fn ($req) => $req->url() === 'https://hris.abc.test/hook' && $req->hasHeader('X-PPTI-Signature') && $req->header('X-PPTI-Event')[0] === 'enrollment.status_changed');

        // transfer sponsor resmi ABC → XYZ: ABC hanya diberi tahu "out", XYZ menerima data enrollment (R5, §20.20)
        $sponsorship = app(SponsorshipService::class);
        $t = $sponsorship->request($e->refresh(), $this->xyz, today(), 'Biaya dialihkan', 'MEMO-1', null, $this->admin);
        $sponsorship->approve($t, User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
        $out = WebhookDelivery::query()->where('api_client_id', $abcClient->id)->where('event', 'enrollment.sponsorship_transferred')->firstOrFail();
        $this->assertSame(['id' => $e->id, 'direction' => 'out'], array_intersect_key($out->payload['data'], ['id' => 1, 'direction' => 1]));
        $this->assertArrayNotHasKey('participant', $out->payload['data']);
        $in = WebhookDelivery::query()->where('api_client_id', $xyzClient->id)->where('event', 'enrollment.sponsorship_transferred')->firstOrFail();
        $this->assertSame('in', $in->payload['data']['direction']);
        $this->getJson(self::BASE."/enrollments/{$e->id}", $this->h($key))->assertNotFound();
    }

    public function test_openapi_documents_enterprise_endpoints_and_matches_responses(): void
    {
        $spec = PublicApiSpec::toArray();
        foreach (array_keys(ApiClient::ENTERPRISE_WEBHOOK_EVENTS) as $event) {
            $this->assertArrayHasKey($event, $spec['webhooks']);
        }
        $this->assertArrayHasKey('IdempotencyKey', $spec['components']['parameters']);
        $this->assertSame(['$ref' => '#/components/parameters/IdempotencyKey'], $spec['paths']['/corporate/requests']['post']['parameters'][0]);
        $this->assertContains('enrollments.read', $spec['components']['schemas']['Client']['properties']['scopes']['items']['enum']);

        // bentuk respons = skema yang didokumentasikan
        [, $key] = $this->client($this->hr);
        $this->member('Budi', 'budi@example.com');
        $r = $this->toScheduled($this->newRequest($key), $key);
        $this->assign($key, $r, ['name' => 'Budi', 'email' => 'budi@example.com'])->assertCreated();
        $props = fn (string $name) => array_keys($spec['components']['schemas'][$name]['properties']);
        $this->assertEqualsCanonicalizing($props('Enrollment'), array_keys($this->getJson(self::BASE.'/enrollments', $this->h($key))->json('data.0')));
        $this->assertEqualsCanonicalizing($props('TrainingRequest'), array_keys($this->getJson(self::BASE.'/corporate/requests', $this->h($key))->json('data.0')));
        $this->assertEqualsCanonicalizing($props('Organization'), array_keys($this->getJson(self::BASE.'/corporate/organization', $this->h($key))->json('data')));
        $this->assertEqualsCanonicalizing($props('Analytics'), array_keys($this->getJson(self::BASE.'/analytics', $this->h($key))->json('data')));
        $this->assertEqualsCanonicalizing($props('Seat'), array_keys($this->getJson(self::BASE."/corporate/requests/{$r->code}", $this->h($key))->json('data.seat_list.0')));
    }

    public function test_admin_binds_client_to_organization(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        Livewire::actingAs($admin)->test(CreateApiClient::class)
            ->fillForm(['name' => 'Tanpa organisasi', 'scopes' => ['enrollments.read'], 'rate_limit' => 60])
            ->call('create')
            ->assertHasFormErrors(['scopes']);

        Livewire::actingAs($admin)->test(CreateApiClient::class)
            ->fillForm(['name' => 'HRIS ABC', 'organization_id' => $this->abc->id, 'corporate_user_id' => $this->hr->id, 'scopes' => ['organization.read', 'enrollments.read'],
                'rate_limit' => 120, 'webhook_url' => 'https://hris.abc.test/hook', 'webhook_events' => ['enrollment.created']])
            ->call('create')
            ->assertHasNoFormErrors();
        $client = ApiClient::query()->where('name', 'HRIS ABC')->firstOrFail();
        $this->assertSame($this->abc->id, $client->organization_id);
        $this->assertSame($this->hr->id, $client->corporate_user_id);
        $this->actingAs($admin)->get('/admin/api-clients')->assertOk()->assertSee($this->abc->code);
        $this->actingAs($admin)->get("/admin/api-clients/{$client->id}/edit")->assertOk();
    }
}
