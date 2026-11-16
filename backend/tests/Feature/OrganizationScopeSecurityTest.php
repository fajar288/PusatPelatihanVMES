<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PaymentTransaction;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CompetencyGapService;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\PaymentService;
use App\Services\SponsorshipService;
use App\Services\TrainingRequestService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5H — hardening organization scope (PRD Roadmap 5 §7.1, §20.11, §20.19 R3/R5, §20.21, §20.22):
 * IDOR antar perusahaan di SEMUA rute Corporate Portal (dibangkitkan dari daftar rute, jadi rute baru otomatis ikut
 * diuji), referensi lintas organisasi di isi permintaan, ekspor sesuai scope, pencabutan sesi/token/klien API, dan
 * isolasi API enterprise. Tolakan selalu 404 agar keberadaan data organisasi lain tidak bocor.
 */
class OrganizationScopeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'api/v1/corporate/organizations/{organization}';

    private const API = '/api/public/v1';

    private OrganizationService $orgs;

    private Organization $abc;

    private Organization $xyz;

    private OrganizationUnit $abcEng;

    private OrganizationUnit $xyzOps;

    private TrainingSchedule $plc;

    private TrainingSchedule $scada;

    private User $abcOwner;

    private User $abcHr;

    private CorporateUser $xyzOwnerCu;

    private CorporateUser $xyzViewerCu;

    private Member $budi;

    private Member $sari;

    private Enrollment $abcEnrollment;

    private Enrollment $budiSelf;

    private Enrollment $xyzEnrollment;

    private TrainingRequest $abcRequest;

    private TrainingRequest $xyzRequest;

    private Invoice $xyzInvoice;

    private CompetencyTarget $xyzTarget;

    private EmploymentRelationship $xyzEmployment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->orgs = app(OrganizationService::class);
        $employment = app(EmploymentService::class);

        $this->abc = $this->orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->xyz = $this->orgs->create(['legal_name' => 'PT XYZ Energi']);
        $this->abcEng = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Engineering']);
        $this->xyzOps = OrganizationUnit::query()->create(['organization_id' => $this->xyz->id, 'name' => 'Operations']);
        [$this->plc, $this->scada] = TrainingSchedule::query()->orderBy('id')->take(2)->get()->all();

        $this->abcOwner = $this->user('Olla Owner ABC');
        $this->orgs->addCorporateUser($this->abc, $this->abcOwner, 'owner');
        $this->abcHr = $this->user('Hana HR ABC');
        $this->orgs->addCorporateUser($this->abc, $this->abcHr, 'hr_admin');
        $this->xyzOwnerCu = $this->orgs->addCorporateUser($this->xyz, $this->user('Xena Owner XYZ'), 'owner');
        $this->xyzViewerCu = $this->orgs->addCorporateUser($this->xyz, $this->user('Vino Viewer XYZ'), 'viewer');

        $this->budi = $this->member('Budi Santoso', 'budi@abc.co.id');
        $this->sari = $this->member('Sari Wulandari', 'sari@xyz.co.id');
        $employment->start($this->budi, $this->abc, ['employee_id' => 'ABC-001', 'unit_id' => $this->abcEng->id, 'position' => 'Teknisi']);
        $this->xyzEmployment = $employment->start($this->sari, $this->xyz, ['employee_id' => 'XYZ-001', 'unit_id' => $this->xyzOps->id, 'position' => 'Operator']);

        $this->abcEnrollment = $this->enroll($this->budi, $this->plc, $this->abc, $this->abcEng);
        $this->budiSelf = $this->enroll($this->budi, $this->scada, null);
        $this->xyzEnrollment = $this->enroll($this->sari, $this->plc, $this->xyz, $this->xyzOps);

        $this->abcRequest = $this->approvedRequest($this->abc, $this->abcOwner);
        $this->xyzRequest = $this->approvedRequest($this->xyz, $this->xyzOwnerCu->user);
        $this->xyzInvoice = $this->xyzRequest->invoices()->firstOrFail();

        $competency = Competency::query()->create(['code' => 'NET', 'name' => 'Jaringan Komputer']);
        $this->xyzTarget = app(CompetencyGapService::class)->setTarget($this->xyz, 'Operator', $competency, 70, $this->xyzOwnerCu->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- fixture ----------

    private function user(string $name, ?string $email = null): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email ?? Str::slug($name).'@example.com', 'role' => User::ROLE_STUDENT]);
    }

    private function member(string $name, string $email): Member
    {
        return app(MemberService::class)->createForUser($this->user($name, $email))->load('user');
    }

    private function enroll(Member $m, TrainingSchedule $s, ?Organization $sponsor, ?OrganizationUnit $unit = null): Enrollment
    {
        return Enrollment::query()->create(['schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => $m->user->name, 'participant_email' => $m->user->email, 'enrolled_at' => now()]
            + ($sponsor ? ['type' => Enrollment::TYPE_CORPORATE, 'sponsor_organization_id' => $sponsor->id, 'sponsor_unit_id' => $unit?->id] : []));
    }

    /** Permintaan 2 kursi yang penawarannya sudah disetujui (invoice organisasi terbit). */
    private function approvedRequest(Organization $org, User $owner): TrainingRequest
    {
        $code = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/corporate/organizations/{$org->code}/requests", [
            'package' => $this->plc->package->slug, 'seats' => 2, 'delivery' => 'onsite', 'target_date' => '2026-12-01', 'submit' => true,
        ])->assertCreated()->json('data.code');
        $r = TrainingRequest::query()->where('code', $code)->firstOrFail();
        app(TrainingRequestService::class)->quote($r, User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]), 5000000, 0, '2026-11-30');
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/corporate/organizations/{$org->code}/requests/{$code}/accept-quote")->assertOk();
        $this->app['auth']->forgetGuards();

        return $r->refresh();
    }

    /** @return list<RouteDefinition> */
    private function portalRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with($r->uri(), self::PORTAL))->values()->all();
    }

    /** Ganti setiap parameter rute dengan sumber daya milik organisasi lain (XYZ). */
    private function foreignUri(RouteDefinition $route, Organization $urlOrg): string
    {
        $values = [
            'organization' => $urlOrg->code,
            'enrollment' => $this->xyzEnrollment->id,
            'invoice' => $this->xyzInvoice->invoice_number,
            'trainingRequest' => $this->xyzRequest->code,
            'seat' => $this->xyzRequest->requestSeats()->orderBy('seat_no')->value('id'),
            'corporateUser' => $this->xyzViewerCu->id,
            'competencyTarget' => $this->xyzTarget->id,
            'action' => 'cancel',
        ];
        $unknown = array_diff($route->parameterNames(), array_keys($values));
        $this->assertSame([], array_values($unknown), "Parameter rute {$route->uri()} belum dipetakan di tes IDOR — tambahkan sumber daya organisasi lain untuknya.");

        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) $values[$m[1]], $route->uri());
    }

    /** Isi permintaan yang valid untuk semua rute tulis (agar tolakan berasal dari scope, bukan validasi). */
    private function body(): array
    {
        return [
            'role' => 'viewer', 'reason' => 'uji', 'note' => 'uji', 'name' => 'Penyusup', 'email' => 'penyusup@example.com',
            'employment_id' => $this->xyzEmployment->id, 'position' => 'Operator', 'competency' => 'NET', 'required_level' => 10,
            'package' => $this->plc->package->slug, 'seats' => 1, 'delivery' => 'onsite',
        ];
    }

    private function send(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->json($method, $uri, in_array($method, ['GET', 'HEAD'], true) ? [] : $body);
    }

    private function xyzStateFingerprint(): array
    {
        return [
            'request' => $this->xyzRequest->fresh()->status,
            'seats' => RequestSeat::query()->where('training_request_id', $this->xyzRequest->id)->orderBy('id')->get(['status', 'member_id', 'enrollment_id'])->toArray(),
            'viewer' => $this->xyzViewerCu->fresh()->only(['status', 'role', 'unit_id']),
            'target' => CompetencyTarget::query()->whereKey($this->xyzTarget->id)->value('required_level'),
            'enrollment' => $this->xyzEnrollment->fresh()->only(['status', 'sponsor_organization_id', 'sponsor_unit_id']),
            'invoice' => $this->xyzInvoice->fresh()->status,
            'transactions' => PaymentTransaction::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'corporateUsers' => CorporateUser::query()->count(),
        ];
    }

    private function csvRows(TestResponse $res): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($res->streamedContent(), "\xEF\xBB\xBF"))));

        return array_map(fn ($l) => str_getcsv($l), array_slice($lines, 1));
    }

    // ---------- §20.21: organisasi lain lewat URL — semua rute portal ----------

    public function test_every_portal_route_rejects_another_organization_in_the_url(): void
    {
        $before = $this->xyzStateFingerprint();
        $routes = $this->portalRoutes();
        $this->assertGreaterThanOrEqual(29, count($routes), 'Daftar rute portal perusahaan tidak terbaca.');

        $checked = 0;
        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($this->abcOwner, 'sanctum');
                $res = $this->send($method, $this->foreignUri($route, $this->xyz), $this->body());
                $this->assertSame(404, $res->status(), "$method {$route->uri()} dengan organisasi lain di URL harus 404, didapat {$res->status()}.");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(29, $checked);
        $this->assertSame($before, $this->xyzStateFingerprint(), 'Tidak boleh ada data organisasi lain yang berubah.');
        $this->assertSame(0, AuditLog::query()->where('actor_id', $this->abcOwner->id)->where('action', 'like', 'corporate.%')->count(), 'Akses yang ditolak tidak boleh tercatat sebagai akses data.');
    }

    public function test_every_portal_route_requires_authentication(): void
    {
        foreach ($this->portalRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->app['auth']->forgetGuards();
                $this->send($method, $this->foreignUri($route, $this->abc), $this->body())->assertUnauthorized();
            }
        }
        $this->getJson('/api/v1/corporate/organizations')->assertUnauthorized();
    }

    // ---------- IDOR bertingkat: organisasi sendiri di URL, sumber daya organisasi lain di path ----------

    public function test_nested_resources_of_another_organization_are_not_found_under_own_organization(): void
    {
        $before = $this->xyzStateFingerprint();
        $nested = array_filter($this->portalRoutes(), fn (RouteDefinition $r) => count($r->parameterNames()) > 1);
        $this->assertGreaterThanOrEqual(15, count($nested));

        foreach ($nested as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($this->abcOwner, 'sanctum');
                $res = $this->send($method, $this->foreignUri($route, $this->abc), $this->body());
                $this->assertSame(404, $res->status(), "$method {$route->uri()} dengan sumber daya organisasi lain harus 404, didapat {$res->status()}.");
            }
        }

        $this->assertSame($before, $this->xyzStateFingerprint());
    }

    public function test_seat_of_own_request_cannot_be_reached_through_another_request_and_vice_versa(): void
    {
        $abcSeat = $this->abcRequest->requestSeats()->orderBy('seat_no')->firstOrFail();
        $xyzSeat = $this->xyzRequest->requestSeats()->orderBy('seat_no')->firstOrFail();
        $base = "/api/v1/corporate/organizations/{$this->abc->code}/requests";

        // kursi XYZ lewat permintaan ABC, dan kursi ABC lewat kode permintaan XYZ
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/{$this->abcRequest->code}/seats/{$xyzSeat->id}/release", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/{$this->xyzRequest->code}/seats/{$abcSeat->id}/release", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/{$this->abcRequest->code}/seats/{$xyzSeat->id}/assign", ['name' => 'X'])->assertNotFound();
        $this->assertSame('reserved', $xyzSeat->fresh()->status);
    }

    // ---------- referensi lintas organisasi di isi permintaan ----------

    public function test_body_references_to_another_organization_are_rejected(): void
    {
        $base = "/api/v1/corporate/organizations/{$this->abc->code}";
        $seat = $this->abcRequest->requestSeats()->orderBy('seat_no')->firstOrFail();
        $enrollments = Enrollment::query()->count();

        // karyawan XYZ dipilih untuk kursi ABC
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/requests/{$this->abcRequest->code}/seats/{$seat->id}/assign-employee", ['employment_id' => $this->xyzEmployment->id])->assertNotFound();
        // unit XYZ dipakai di permintaan, penugasan, dan pengguna perusahaan ABC
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/requests", ['package' => $this->plc->package->slug, 'seats' => 1, 'delivery' => 'onsite', 'unit_id' => $this->xyzOps->id])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/requests/{$this->abcRequest->code}/seats/{$seat->id}/assign", ['name' => 'Baru', 'email' => 'baru@abc.co.id', 'unit_id' => $this->xyzOps->id])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->actingAs($this->abcOwner, 'sanctum')->postJson("$base/users", ['email' => $this->abcHr->email, 'role' => 'supervisor', 'unit_id' => $this->xyzOps->id])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        // parameter organisasi tambahan di query/isi diabaikan: data tetap dari organisasi di URL yang sudah diverifikasi
        $ids = collect($this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments?organization={$this->xyz->code}&organization_id={$this->xyz->id}&sponsor_organization_id={$this->xyz->id}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->abcEnrollment->id], $ids);

        $this->assertSame('reserved', $seat->fresh()->status);
        $this->assertSame($enrollments, Enrollment::query()->count());
        $this->assertSame('hr_admin', CorporateUser::query()->where('organization_id', $this->abc->id)->where('user_id', $this->abcHr->id)->value('role'));
    }

    public function test_own_employee_self_funded_and_other_sponsor_enrollments_stay_hidden(): void
    {
        $base = "/api/v1/corporate/organizations/{$this->abc->code}";
        // Budi karyawan ABC, tetapi training SCADA-nya dibiayai sendiri (R2/R3)
        $this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments/{$this->budiSelf->id}")->assertNotFound();
        $this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments/{$this->xyzEnrollment->id}")->assertNotFound();
        $this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments/{$this->abcEnrollment->id}")->assertOk();

        $listed = collect($this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments")->json('data'))->pluck('id')->all();
        $this->assertSame([$this->abcEnrollment->id], $listed);
        $employees = collect($this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/employees")->json('data'))->pluck('employeeId')->all();
        $this->assertSame(['ABC-001'], $employees);
    }

    // ---------- §20.21: ekspor mengikuti izin & scope yang sama dengan dashboard ----------

    public function test_export_never_exceeds_organization_unit_or_role_scope(): void
    {
        $hrd = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'HRD']);
        $dewi = $this->member('Dewi Lestari', 'dewi@abc.co.id');
        $this->enroll($dewi, $this->scada, $this->abc, $hrd);
        $url = "/api/v1/corporate/organizations/{$this->abc->code}/enrollments-export";

        $rows = $this->csvRows($this->actingAs($this->abcOwner, 'sanctum')->get($url)->assertOk());
        $this->assertEqualsCanonicalizing(['Budi Santoso', 'Dewi Lestari'], array_column($rows, 0), 'Hanya enrollment bersponsor ABC; enrollment mandiri Budi & peserta XYZ tidak ikut.');
        $this->assertCount(2, $rows);
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate.exported', 'actor_id' => $this->abcOwner->id, 'auditable_id' => $this->abc->id]);

        // HR dengan batas unit hanya mengekspor unitnya
        $unitHr = $this->user('Ujang HR Engineering');
        $this->orgs->addCorporateUser($this->abc, $unitHr, 'hr_admin', $this->abcEng);
        $this->app['auth']->forgetGuards();
        $this->assertSame(['Budi Santoso'], array_column($this->csvRows($this->actingAs($unitHr, 'sanctum')->get($url)->assertOk()), 0));

        // peran tanpa izin ekspor ditolak walau bisa melihat dashboard
        foreach (['supervisor', 'viewer', 'finance'] as $role) {
            $u = $this->user("Peran $role");
            $this->orgs->addCorporateUser($this->abc, $u, $role, $role === 'supervisor' ? $this->abcEng : null);
            $this->app['auth']->forgetGuards();
            $this->actingAs($u, 'sanctum')->get($url)->assertForbidden();
        }

        // kebijakan exit_access=none: peserta yang sudah resign tidak tampil per nama di ekspor
        $budiEmployment = EmploymentRelationship::query()->where('member_id', $this->budi->id)->where('organization_id', $this->abc->id)->firstOrFail();
        app(EmploymentService::class)->end($budiEmployment);
        $this->abc->update(['settings' => ['exit_access' => 'none']]);
        $this->app['auth']->forgetGuards();
        $this->assertSame(['Dewi Lestari'], array_column($this->csvRows($this->actingAs($this->abcOwner, 'sanctum')->get($url)->assertOk()), 0));
    }

    // ---------- §20.21: sesi & token dapat dicabut ----------

    private function login(User $u): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk()->json('token');
    }

    private function withBearer(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $body);
    }

    public function test_disabling_corporate_user_revokes_corporate_access_of_an_existing_token_immediately(): void
    {
        $token = $this->login($this->abcHr);
        $summary = "/api/v1/corporate/organizations/{$this->abc->code}";
        $this->withBearer($token, 'GET', $summary)->assertOk();

        $hrCu = CorporateUser::query()->where('organization_id', $this->abc->id)->where('user_id', $this->abcHr->id)->firstOrFail();
        $ownerToken = $this->login($this->abcOwner);
        $this->withBearer($ownerToken, 'POST', "$summary/users/{$hrCu->id}/disable")->assertOk();

        $this->withBearer($token, 'GET', $summary)->assertNotFound();
        $this->withBearer($token, 'GET', "$summary/enrollments-export")->assertNotFound();
        $this->withBearer($token, 'GET', '/api/v1/corporate/organizations')->assertOk()->assertJsonCount(0, 'data');
        // akun pribadinya tetap bisa dipakai (portal peserta tidak ikut terkunci)
        $this->withBearer($token, 'GET', '/api/v1/me')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate_user.disabled', 'auditable_id' => $hrCu->id, 'actor_id' => $this->abcOwner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $this->abcHr->id]);
    }

    public function test_employment_end_and_organization_suspension_revoke_corporate_access(): void
    {
        $budiUser = $this->budi->user;
        $this->orgs->addCorporateUser($this->abc, $budiUser, 'viewer');
        $token = $this->login($budiUser);
        $summary = "/api/v1/corporate/organizations/{$this->abc->code}";
        $this->withBearer($token, 'GET', $summary)->assertOk();

        // §20.22 "Corporate access dapat dicabut setelah employment berakhir"
        $employment = EmploymentRelationship::query()->where('member_id', $this->budi->id)->where('organization_id', $this->abc->id)->firstOrFail();
        app(EmploymentService::class)->end($employment, '2026-11-10', 'resigned');
        $this->withBearer($token, 'GET', $summary)->assertNotFound();
        // histori & sertifikat peserta tetap miliknya
        $this->withBearer($token, 'GET', '/api/v1/me/enrollments')->assertOk();

        // organisasi dinonaktifkan → seluruh penggunanya tertolak, termasuk Owner
        $ownerToken = $this->login($this->abcOwner);
        $this->withBearer($ownerToken, 'GET', $summary)->assertOk();
        $this->orgs->setStatus($this->abc, 'inactive');
        $this->withBearer($ownerToken, 'GET', $summary)->assertNotFound();
        $this->withBearer($ownerToken, 'GET', "$summary/enrollments-export")->assertNotFound();
    }

    public function test_logout_admin_session_revocation_and_token_expiry(): void
    {
        $summary = "/api/v1/corporate/organizations/{$this->abc->code}";

        // logout mencabut token yang dipakai dan diaudit
        $token = $this->login($this->abcOwner);
        $this->withBearer($token, 'POST', '/api/v1/auth/logout')->assertOk();
        $this->withBearer($token, 'GET', $summary)->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $this->abcOwner->id]);

        // admin VMES mengeluarkan akun dari semua perangkat
        $laptop = $this->login($this->abcOwner);
        $phone = $this->login($this->abcOwner);
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->assertSame(2, $this->orgs->revokeSessions($this->abcOwner, 'Laptop hilang', $admin));
        $this->withBearer($laptop, 'GET', $summary)->assertUnauthorized();
        $this->withBearer($phone, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.sessions_revoked', 'auditable_id' => $this->abcOwner->id, 'actor_id' => $admin->id]);

        // token kedaluwarsa setelah umur sesi (SANCTUM_EXPIRATION, default 30 hari = umur cookie frontend)
        $this->assertSame(60 * 24 * 30, config('sanctum.expiration'));
        $token = $this->login($this->abcOwner);
        $this->withBearer($token, 'GET', $summary)->assertOk();
        Carbon::setTestNow(now()->addDays(31));
        $this->withBearer($token, 'GET', $summary)->assertUnauthorized();
    }

    public function test_failed_login_to_an_existing_account_is_audited_without_storing_unknown_emails(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->abcOwner->email, 'password' => 'salah'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'tidak-ada@example.com', 'password' => 'salah'])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'auditable_id' => $this->abcOwner->id, 'actor_id' => null]);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    // ---------- §15 & §20.21: jejak audit untuk akses, penugasan, peran, pembayaran, enrollment, sertifikat ----------

    public function test_audit_trail_covers_access_assignment_roles_payment_enrollment_and_certificates(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $base = "/api/v1/corporate/organizations/{$this->abc->code}";
        $token = $this->login($this->abcOwner);
        $invoice = $this->abcRequest->invoices()->firstOrFail();

        // akses data peserta & ekspor
        foreach (['/enrollments', "/enrollments/{$this->abcEnrollment->id}", '/certificates', '/employees', '/analytics', '/competencies', "/invoices/{$invoice->invoice_number}/pdf"] as $path) {
            $this->withBearer($token, 'GET', $base.$path)->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->get("$base/enrollments-export")->assertOk()->streamedContent();

        // pembayaran: bukti dari finance perusahaan → verifikasi finance VMES → permintaan lunas → dijadwalkan
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->post("$base/invoices/{$invoice->invoice_number}/proof", ['proof' => UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $tx = PaymentTransaction::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        app(PaymentService::class)->verify($tx, $admin);
        app(TrainingRequestService::class)->schedule($this->abcRequest->refresh(), $this->plc, $admin);

        // penugasan peserta & pelepasan kursi (enrollment dibuat lalu dibatalkan)
        $dewi = $this->member('Dewi Lestari', 'dewi@abc.co.id');
        $dewi->user->forceFill(['email_verified_at' => now()])->save();
        $seat = $this->abcRequest->requestSeats()->orderBy('seat_no')->firstOrFail();
        $this->withBearer($token, 'POST', "$base/requests/{$this->abcRequest->code}/seats/{$seat->id}/assign", ['name' => 'Dewi Lestari', 'email' => 'dewi@abc.co.id', 'member_number' => $dewi->member_number])->assertOk();
        $enrollment = Enrollment::query()->where('member_id', $dewi->id)->firstOrFail();
        $this->withBearer($token, 'POST', "$base/requests/{$this->abcRequest->code}/seats/{$seat->id}/release", ['reason' => 'Batal ikut'])->assertOk();

        // peran pengguna perusahaan
        $rina = $this->user('Rina Finance');
        $rinaId = $this->withBearer($token, 'POST', "$base/users", ['email' => $rina->email, 'role' => 'viewer'])->assertCreated()->json('data.id');
        $this->withBearer($token, 'PATCH', "$base/users/$rinaId", ['role' => 'finance'])->assertOk();
        $this->withBearer($token, 'POST', "$base/users/$rinaId/disable")->assertOk();

        // sponsor & sertifikat (admin VMES)
        $sponsorship = app(SponsorshipService::class);
        $approver = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $sponsorship->approve($sponsorship->request($this->abcEnrollment, $this->xyz, '2026-11-10', 'Biaya dialihkan', 'SURAT-01', null, $admin), $approver);
        $certs = app(CertificateService::class);
        $c = $certs->createDraft($this->xyzEnrollment->refresh(), $admin, [], true, 'Uji audit');
        $certs->issue($certs->submit($c, $admin), $admin, false);

        $this->withBearer($token, 'POST', '/api/v1/auth/logout')->assertOk();

        $expect = [
            'auth.login' => $this->abcOwner, 'auth.logout' => $this->abcOwner,
            'corporate.participants_viewed' => $this->abcOwner, 'corporate.participant_progress_viewed' => $this->abcOwner,
            'corporate.certificates_viewed' => $this->abcOwner, 'corporate.employees_viewed' => $this->abcOwner,
            'corporate.analytics_viewed' => $this->abcOwner, 'corporate.competency_matrix_viewed' => $this->abcOwner,
            'corporate.invoice_downloaded' => $this->abcOwner, 'corporate.exported' => $this->abcOwner,
            'payment.proof_submitted' => $this->abcOwner, 'payment.verified' => $admin, 'training_request.scheduled' => $admin,
            'participant.assigned' => $this->abcOwner, 'enrollment.created' => null, 'request_seat.released' => $this->abcOwner, 'enrollment.dropped' => $this->abcOwner,
            'corporate_user.added' => $this->abcOwner, 'corporate_user.role_changed' => $this->abcOwner, 'corporate_user.disabled' => $this->abcOwner,
            'sponsorship_transfer.requested' => $admin, 'sponsorship_transfer.approved' => $approver, 'enrollment.sponsor_transferred' => $approver,
            'certificate.created' => $admin, 'certificate.issued' => $admin,
        ];
        foreach ($expect as $action => $actor) {
            $q = AuditLog::query()->where('action', $action);
            $this->assertTrue((clone $q)->exists(), "Aksi \"$action\" tidak tercatat di audit_logs.");
            if ($actor) {
                $this->assertTrue((clone $q)->where('actor_id', $actor->id)->exists(), "Aksi \"$action\" tidak mencatat pelakunya ({$actor->name}).");
            }
        }
        $this->assertTrue(AuditLog::query()->where('action', 'enrollment.created')->where('auditable_id', $enrollment->id)->exists());
    }

    // ---------- indeks & beban kueri dashboard ----------

    public function test_dashboard_query_indexes_exist(): void
    {
        $migration = require database_path('migrations/2026_11_16_140000_add_dashboard_query_indexes.php');
        foreach ($migration::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                $this->assertTrue(Schema::hasIndex($table, $columns), "Indeks $table(".implode(', ', $columns).') belum ada.');
            }
        }
    }

    public function test_participant_lists_and_export_query_count_does_not_grow_per_row(): void
    {
        $abcOwnerCu = CorporateUser::query()->where('organization_id', $this->abc->id)->where('user_id', $this->abcOwner->id)->firstOrFail();
        [, $key] = $this->apiClient($this->abc, $abcOwnerCu);
        $base = "/api/v1/corporate/organizations/{$this->abc->code}";
        $queries = function (callable $request): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $request();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $measure = fn () => [
            'portal' => $queries(fn () => $this->actingAs($this->abcOwner, 'sanctum')->getJson("$base/enrollments?per_page=100")->assertOk()),
            'export' => $queries(fn () => $this->actingAs($this->abcOwner, 'sanctum')->get("$base/enrollments-export")->assertOk()->streamedContent()),
            'api' => $queries(fn () => $this->api($key, 'GET', '/enrollments?per_page=100')->assertOk()),
        ];

        $few = $measure();
        foreach (range(1, 20) as $i) {
            $m = $this->member("Peserta $i", "peserta$i@abc.co.id");
            $emp = app(EmploymentService::class)->start($m, $this->abc, ['employee_id' => "ABC-1$i"]);
            if ($i % 4 === 0) {
                app(EmploymentService::class)->end($emp);   // sebagian sudah resign: status keluar ikut dihitung batch
            }
            $this->enroll($m, $this->plc, $this->abc, $this->abcEng);
        }
        $many = $measure();

        foreach ($few as $endpoint => $n) {
            $this->assertLessThanOrEqual($n + 3, $many[$endpoint], "$endpoint menjalankan kueri per baris (N+1): $n kueri untuk 1 baris, {$many[$endpoint]} untuk 21 baris.");
        }
    }

    // ---------- API enterprise: klien terikat organisasi ----------

    /** @return array{0: ApiClient, 1: string} */
    private function apiClient(Organization $org, CorporateUser $cu): array
    {
        $c = new ApiClient(['name' => 'HRIS '.$org->code, 'organization_id' => $org->id, 'corporate_user_id' => $cu->id, 'scopes' => array_keys(ApiClient::allScopes()), 'rate_limit' => 1000]);
        $key = $c->generateKey();
        $c->save();

        return [$c, $key];
    }

    private function api(string $key, string $method, string $path, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, self::API.$path, $body, ['X-Api-Key' => $key, 'Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_enterprise_api_cannot_reach_another_organization_by_identifier(): void
    {
        $abcOwnerCu = CorporateUser::query()->where('organization_id', $this->abc->id)->where('user_id', $this->abcOwner->id)->firstOrFail();
        [, $key] = $this->apiClient($this->abc, $abcOwnerCu);
        $before = $this->xyzStateFingerprint();
        $r = $this->xyzRequest->code;
        $seatNo = $this->xyzRequest->requestSeats()->orderBy('seat_no')->value('seat_no');

        foreach ([
            ['GET', "/corporate/requests/$r"],
            ['POST', "/corporate/requests/$r/cancel"],
            ['POST', "/corporate/requests/$r/participants", ['name' => 'Penyusup', 'email' => 'p@example.com']],
            ['POST', "/corporate/requests/$r/seats/$seatNo/release", ['reason' => 'x']],
            ['GET', "/corporate/employees/{$this->xyzEmployment->code}"],
            ['POST', "/corporate/employees/{$this->xyzEmployment->code}/end"],
            ['GET', "/enrollments/{$this->xyzEnrollment->id}"],
            ['GET', "/enrollments/{$this->budiSelf->id}"],
            ['GET', "/payments/{$this->xyzInvoice->invoice_number}"],
        ] as $call) {
            $this->assertSame(404, $this->api($key, $call[0], $call[1], $call[2] ?? [])->status(), "{$call[0]} {$call[1]} milik organisasi lain harus 404.");
        }
        $this->assertSame($before, $this->xyzStateFingerprint());
        $this->assertTrue($this->xyzEmployment->fresh()->isActive());

        // filter organisasi di query diabaikan: organisasi selalu dari klien
        $q = "?organization={$this->xyz->code}&organization_id={$this->xyz->id}";
        $this->assertSame([$this->abcEnrollment->id], collect($this->api($key, 'GET', "/enrollments$q")->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$this->abcRequest->code], collect($this->api($key, 'GET', "/corporate/requests$q")->assertOk()->json('data'))->pluck('code')->all());
        $this->assertSame($this->abc->code, $this->api($key, 'GET', "/corporate/organization$q")->assertOk()->json('data.code'));
        $payments = collect($this->api($key, 'GET', "/payments$q")->assertOk()->json('data'))->pluck('number')->all();
        $this->assertNotContains($this->xyzInvoice->invoice_number, $payments);
        $this->assertNotEmpty($payments);
    }

    public function test_enterprise_api_keys_are_revocable(): void
    {
        $abcOwnerCu = CorporateUser::query()->where('organization_id', $this->abc->id)->where('user_id', $this->abcOwner->id)->firstOrFail();
        [$client, $key] = $this->apiClient($this->abc, $abcOwnerCu);
        $this->api($key, 'GET', '/corporate/organization')->assertOk();

        // kunci dirotasi → kunci lama mati
        $newKey = $client->generateKey();
        $client->save();
        $this->api($key, 'GET', '/corporate/organization')->assertUnauthorized();
        $this->api($newKey, 'GET', '/corporate/organization')->assertOk();

        // pengguna perusahaan yang diwakili dicabut → klien ikut berhenti
        $this->orgs->disableCorporateUser($abcOwnerCu, 'uji');
        $this->api($newKey, 'GET', '/corporate/organization')->assertForbidden()->assertJsonPath('error.code', 'acting_user_inactive');
        $this->orgs->addCorporateUser($this->abc, $this->abcOwner, 'owner');
        $this->api($newKey, 'GET', '/corporate/organization')->assertOk();

        // klien dinonaktifkan / kedaluwarsa
        $client->update(['is_active' => false]);
        $this->api($newKey, 'GET', '/corporate/organization')->assertForbidden();
        $client->update(['is_active' => true, 'expires_at' => now()->subMinute()]);
        $this->api($newKey, 'GET', '/corporate/organization')->assertUnauthorized();

        // organisasi tidak aktif
        $client->update(['expires_at' => null]);
        $this->orgs->setStatus($this->abc, 'inactive');
        $this->api($newKey, 'GET', '/corporate/organization')->assertForbidden()->assertJsonPath('error.code', 'organization_inactive');
    }
}
