<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\IdentityReview;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\EmploymentService;
use App\Services\EnrollmentService;
use App\Services\IdentityMatchingService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\RegistrationService;
use App\Services\SponsorshipService;
use App\Support\Enterprise\IdentityMatch;
use App\Support\Enterprise\SponsorshipBackfill;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5A — fondasi enterprise. Setiap baris tabel PRD Roadmap 5 §11 + skenario §20.7 (resign) dan §20.8
 * (pindah perusahaan), aturan R1–R10 yang relevan, identity matching §20.5, transfer sponsor §20.20, keamanan §20.21.
 */
class EnterpriseFoundationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationService $orgs;

    private EmploymentService $employment;

    private Organization $abc;

    private Organization $xyz;

    private TrainingSchedule $plc;

    private TrainingSchedule $scada;

    private TrainingSchedule $hmi;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        $this->orgs = app(OrganizationService::class);
        $this->employment = app(EmploymentService::class);
        $this->abc = $this->orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->xyz = $this->orgs->create(['legal_name' => 'PT XYZ Energi']);
        [$this->plc, $this->scada, $this->hmi] = TrainingSchedule::query()->orderBy('id')->take(3)->get()->all();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $name, string $email, bool $verified = true, ?string $phone = null): Member
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'email_verified_at' => $verified ? now() : null, 'role' => User::ROLE_STUDENT]);
        $m = app(MemberService::class)->createForUser($user, ['phone' => $phone]);

        return $m->load('user');
    }

    private function corporate(Member $m, Organization $org, TrainingSchedule $s, ?OrganizationUnit $unit = null): Enrollment
    {
        return Enrollment::query()->create(['schedule_id' => $s->id, 'member_id' => $m->id, 'type' => Enrollment::TYPE_CORPORATE, 'sponsor_organization_id' => $org->id,
            'sponsor_unit_id' => $unit?->id, 'participant_name' => $m->user->name, 'participant_email' => $m->user->email, 'enrolled_at' => now()]);
    }

    private function self(Member $m, TrainingSchedule $s): Enrollment
    {
        return Enrollment::query()->create(['schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => $m->user->name, 'participant_email' => $m->user->email, 'enrolled_at' => now()]);
    }

    private function hr(Organization $org, string $role = 'hr_admin', ?OrganizationUnit $unit = null): User
    {
        $u = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->orgs->addCorporateUser($org, $u, $role, $unit);

        return $u;
    }

    /** @return list<int> */
    private function visibleIds(User $u, Organization $org): array
    {
        return collect($this->actingAs($u, 'sanctum')->getJson("/api/v1/corporate/organizations/{$org->code}/enrollments")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function certificate(Enrollment $e): Certificate
    {
        return Certificate::query()->create(['number' => 'PPTI-PST-'.$e->id, 'verification_code' => 'VC'.$e->id, 'enrollment_id' => $e->id, 'member_id' => $e->member_id,
            'recipient_name' => $e->participant_name, 'program_title' => 'Program', 'status' => 'issued', 'issued_at' => now()]);
    }

    // ---------- R1/R2/R7: aturan sponsor di model & database ----------

    public function test_sponsor_rules_enforced_by_model_and_database(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');

        try {
            Enrollment::query()->create(['schedule_id' => $this->plc->id, 'member_id' => $budi->id, 'type' => 'corporate', 'participant_name' => 'Budi']);
            $this->fail('R1 harus ditolak model');
        } catch (DomainException) {
        }
        try {
            Enrollment::query()->create(['schedule_id' => $this->plc->id, 'member_id' => $budi->id, 'type' => 'self', 'sponsor_organization_id' => $this->abc->id, 'participant_name' => 'Budi']);
            $this->fail('R2 harus ditolak model');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('enrollments')->insert(['schedule_id' => $this->plc->id, 'type' => 'corporate', 'sponsor_organization_id' => null, 'participant_name' => 'X', 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_r7_sponsor_cannot_be_changed_silently(): void
    {
        $e = $this->corporate($this->member('Budi', 'budi@example.com'), $this->abc, $this->plc);
        $this->expectException(DomainException::class);
        $e->update(['sponsor_organization_id' => $this->xyz->id]);
    }

    // ---------- §11 tabel skenario ----------

    public function test_s11_existing_account_is_reused_not_duplicated(): void
    {
        $budi = $this->member('Budi Santoso', 'budi@example.com');
        $users = User::count();
        $members = Member::count();

        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR ABC', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT. ABC Manufaktur', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'Budi S.', 'email' => 'BUDI@example.com']]])->assertCreated();
        $enrollments = app(EnrollmentService::class)->fromRegistration(Registration::query()->latest('id')->firstOrFail());

        $this->assertCount(1, $enrollments);
        $this->assertSame($budi->id, $enrollments[0]->member_id);
        $this->assertSame(Enrollment::TYPE_CORPORATE, $enrollments[0]->type);
        $this->assertSame($this->abc->id, $enrollments[0]->sponsor_organization_id, 'nama "PT. ABC Manufaktur" dinormalisasi ke organisasi yang sama');
        $this->assertSame($users, User::count(), 'tidak ada akun kedua');
        $this->assertSame($members, Member::count(), 'tidak ada member kedua');
    }

    public function test_s11_self_funded_has_no_sponsor_and_is_private_from_corporate(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $corp = $this->corporate($budi, $this->abc, $this->plc);
        $self = $this->self($budi, $this->hmi);
        $this->assertNull($self->sponsor_organization_id);
        $this->assertSame([$corp->id], $this->visibleIds($this->hr($this->abc), $this->abc));
    }

    public function test_s11_and_s20_7_resignation_keeps_member_history_and_certificate(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $emp = $this->employment->start($budi, $this->abc, ['employee_id' => 'ABC-001', 'position' => 'Maintenance Technician', 'start_date' => '2026-01-10']);
        $this->orgs->addCorporateUser($this->abc, $budi->user, 'viewer');
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $this->certificate($plc);
        $hr = $this->hr($this->abc);

        $row = collect($this->actingAs($hr, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->json('data'))->firstWhere('id', $plc->id);
        $this->assertTrue($row['progressVisible']);
        $this->assertSame('ABC-001', $row['employeeId']);

        $this->employment->end($emp, '2027-06-30', 'resigned');

        // Employment INACTIVE; member, enrollment, sertifikat tetap (R4, R9)
        $this->assertSame('inactive', $emp->fresh()->status);
        $this->assertNotNull(Member::find($budi->id));
        $this->assertSame($this->abc->id, $plc->fresh()->sponsor_organization_id);
        $this->assertSame('issued', $plc->certificate()->first()->status);

        // Akses corporate Budi sendiri di PT ABC dicabut
        $this->assertSame('disabled', CorporateUser::query()->where('user_id', $budi->user_id)->value('status'));
        $this->actingAs($budi->user, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->assertNotFound();

        // Kebijakan bawaan (summary): tetap terdaftar & status sertifikat terlihat, detail progres tertutup
        $row = collect($this->actingAs($hr, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->json('data'))->firstWhere('id', $plc->id);
        $this->assertFalse($row['progressVisible']);
        $this->assertNull($row['attendancePercent']);
        $this->assertSame('issued', $row['certificateStatus']);
        $this->assertSame('inactive', $row['employment']);

        // Budi tetap bisa login & melihat sertifikatnya
        $this->actingAs($budi->user, 'sanctum')->getJson('/api/v1/me/certificates')->assertOk()->assertJsonCount(1, 'data');

        // Training baru yang Budi beli sendiri → sponsor NULL, tidak muncul di PT ABC
        $this->self($budi, $this->hmi);
        $this->assertSame([$plc->id], $this->visibleIds($hr, $this->abc));
        $this->assertDatabaseHas('audit_logs', ['action' => 'employment.ended', 'auditable_id' => $emp->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate_user.disabled']);
    }

    public function test_s11_and_s20_8_moving_company_does_not_carry_old_sponsor_history(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $emp = $this->employment->start($budi, $this->abc, ['employee_id' => 'ABC-001']);
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $hmi = $this->self($budi, $this->hmi);

        $new = $this->employment->move($emp, $this->xyz, ['employee_id' => 'XYZ-77']);
        $scada = $this->corporate($budi, $this->xyz, $this->scada);

        $this->assertSame('transferred', $emp->fresh()->end_reason);
        $this->assertTrue($new->isActive());
        $this->assertSame([$plc->id], $this->visibleIds($this->hr($this->abc), $this->abc), 'PT ABC tidak melihat SCADA (PT XYZ) maupun HMI (mandiri)');
        $this->assertSame([$scada->id], $this->visibleIds($this->hr($this->xyz), $this->xyz), 'PT XYZ tidak memperoleh histori PT ABC');

        $mine = collect($this->actingAs($budi->user, 'sanctum')->getJson('/api/v1/me/enrollments')->assertOk()->json('data'));
        $this->assertCount(3, $mine, 'Budi tetap satu profil dengan seluruh historinya');
        $this->assertSame(1, Member::query()->where('user_id', $budi->user_id)->count());
        $this->assertNotNull($hmi->fresh());
    }

    public function test_s11_one_participant_in_two_corporate_trainings_has_separate_scoped_enrollments(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $a = $this->corporate($budi, $this->abc, $this->plc);
        $b = $this->corporate($budi, $this->abc, $this->scada);
        $c = $this->corporate($budi, $this->xyz, $this->hmi);
        $this->assertSame([$a->id, $b->id], $this->visibleIds($this->hr($this->abc), $this->abc));
        $this->assertSame([$c->id], $this->visibleIds($this->hr($this->xyz), $this->xyz));
    }

    public function test_s11_cancelling_one_participant_changes_only_that_enrollment(): void
    {
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT ABC Manufaktur', 'consent' => true,
            'participants_count' => 3, 'participants' => [['name' => 'A', 'email' => 'a@abc.co.id'], ['name' => 'B', 'email' => 'b@abc.co.id'], ['name' => 'C', 'email' => 'c@abc.co.id']]])->assertCreated();
        [$a, $b, $c] = app(EnrollmentService::class)->fromRegistration(Registration::query()->latest('id')->firstOrFail());
        $before = [$a->fresh()->updated_at, $c->fresh()->updated_at];

        $this->travel(5)->minutes();
        app(EnrollmentService::class)->drop($b, 'Karyawan dinas luar');

        $this->assertSame('dropped', $b->fresh()->status);
        $this->assertSame(['enrolled', 'enrolled'], [$a->fresh()->status, $c->fresh()->status]);
        $this->assertEquals($before, [$a->fresh()->updated_at, $c->fresh()->updated_at]);
    }

    public function test_s11_repeated_confirmation_is_idempotent(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT ABC', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'Budi', 'email' => 'budi@example.com']]])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        app(EnrollmentService::class)->fromRegistration($reg);
        app(EnrollmentService::class)->fromRegistration($reg);
        $this->assertSame(1, Enrollment::query()->where('member_id', $budi->id)->count());
    }

    public function test_s11_corporate_admin_change_does_not_touch_participant_data(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $old = $this->hr($this->abc, 'owner');
        $snapshot = $plc->fresh()->toArray();

        $new = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->orgs->addCorporateUser($this->abc, $new, 'owner');
        $oldCu = CorporateUser::query()->where('user_id', $old->id)->firstOrFail();
        $this->orgs->changeRole($oldCu, 'viewer');
        $this->orgs->disableCorporateUser($oldCu, 'Pergantian admin');

        $this->actingAs($old, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->assertNotFound();
        $this->assertSame([$plc->id], $this->visibleIds($new, $this->abc));
        $this->assertSame($snapshot, $plc->fresh()->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate_user.role_changed']);
    }

    public function test_s11_certificate_owned_by_participant_corporate_sees_only_sponsored_record(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $hmi = $this->self($budi, $this->hmi);
        $this->certificate($plc);
        $this->certificate($hmi);

        $this->actingAs($budi->user, 'sanctum')->getJson('/api/v1/me/certificates')->assertOk()->assertJsonCount(2, 'data');
        $rows = $this->actingAs($this->hr($this->abc), 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('issued', $rows[0]['certificateStatus']);
    }

    // ---------- §20.21 keamanan: scope server-side ----------

    public function test_organization_scope_cannot_be_swapped_via_url(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $this->corporate($budi, $this->xyz, $this->scada);
        $hrAbc = $this->hr($this->abc);

        $this->actingAs($hrAbc, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->xyz->code}/enrollments")->assertNotFound();
        $this->assertTrue($hrAbc->can('viewCorporate', $this->abc));
        $this->assertFalse($hrAbc->can('viewCorporate', $this->xyz));
        $e = $this->corporate($budi, $this->abc, $this->plc);
        $this->assertTrue($hrAbc->can('viewEnrollmentProgress', [$this->abc, $e]));
        $this->assertFalse($hrAbc->can('viewEnrollmentProgress', [$this->xyz, $e]), 'organisasi di URL ditukar → ditolak');
        $e->delete();
        $this->actingAs($hrAbc, 'sanctum')->getJson('/api/v1/corporate/organizations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $this->abc->code);
        $this->assertSame([], $this->visibleIds($hrAbc, $this->abc));

        $this->orgs->setStatus($this->abc, 'inactive');
        $this->actingAs($hrAbc, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate.participants_viewed']);
    }

    public function test_supervisor_sees_only_own_unit(): void
    {
        $eng = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Engineering']);
        $mtc = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Maintenance', 'parent_id' => $eng->id]);
        $hrd = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'HRD']);
        $a = $this->corporate($this->member('A', 'a@x.id'), $this->abc, $this->plc, $eng);
        $b = $this->corporate($this->member('B', 'b@x.id'), $this->abc, $this->plc, $mtc);
        $this->corporate($this->member('C', 'c@x.id'), $this->abc, $this->plc, $hrd);

        $this->assertSame([$a->id, $b->id], $this->visibleIds($this->hr($this->abc, 'supervisor', $eng), $this->abc));
        $this->assertCount(3, $this->visibleIds($this->hr($this->abc), $this->abc));

        $this->expectException(ValidationException::class);
        $this->orgs->addCorporateUser($this->xyz, User::factory()->create(), 'supervisor', $eng);
    }

    public function test_exit_access_policy_none_and_full(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $emp = $this->employment->start($budi, $this->abc);
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $this->employment->end($emp);
        $hr = $this->hr($this->abc);

        $this->abc->update(['settings' => ['exit_access' => 'none']]);
        $this->assertSame([], $this->visibleIds($hr, $this->abc));

        $this->abc->update(['settings' => ['exit_access' => 'full']]);
        $row = $this->actingAs($hr, 'sanctum')->getJson("/api/v1/corporate/organizations/{$this->abc->code}/enrollments")->json('data.0');
        $this->assertSame($plc->id, $row['id']);
        $this->assertTrue($row['progressVisible']);
    }

    // ---------- §20.6 employment ----------

    public function test_employment_rules(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $andi = $this->member('Andi', 'andi@example.com');
        $this->employment->start($budi, $this->abc, ['employee_id' => 'ABC-001']);

        foreach ([fn () => $this->employment->start($budi, $this->abc), fn () => $this->employment->start($andi, $this->abc, ['employee_id' => 'ABC-001'])] as $bad) {
            try {
                $bad();
                $this->fail('harus ditolak');
            } catch (ValidationException) {
            }
        }
        $this->assertNotNull($this->employment->start($budi, $this->xyz, ['employee_id' => 'ABC-001']), 'employee ID boleh sama di organisasi lain');
    }

    // ---------- §20.5 identity matching ----------

    public function test_identity_matching_table(): void
    {
        $svc = app(IdentityMatchingService::class);
        $budi = $this->member('Budi', 'budi@example.com', true, '0812-3456-7890');
        $andi = $this->member('Andi', 'andi@example.com', false);
        $this->employment->start($andi, $this->abc, ['employee_id' => 'ABC-009']);

        $this->assertSame(IdentityMatch::USE_EXISTING, $svc->match(['email' => ' Budi@Example.com '])->outcome);
        $this->assertSame($budi->id, $svc->match(['member_number' => strtolower($budi->member_number)])->member->id);
        $this->assertSame(IdentityMatch::CONFIRM, ($r = $svc->match(['phone' => '+62 812 3456 7890']))->outcome);
        $this->assertSame('phone_match', $r->reason);
        $this->assertSame(IdentityMatch::CONFIRM, $svc->match(['email' => 'andi@example.com'])->outcome, 'email belum terverifikasi → konfirmasi');
        $this->assertSame(IdentityMatch::EXISTING_EMPLOYMENT, $svc->match(['employee_id' => 'ABC-009', 'name' => 'Andi'], $this->abc)->outcome);
        $this->assertSame(IdentityMatch::CREATE_NEW, $svc->match(['name' => 'Baru', 'email' => 'baru@example.com'])->outcome);
        $this->assertSame(IdentityMatch::REVIEW, $svc->match(['name' => 'Tanpa kontak'])->outcome);
        $this->assertSame(IdentityMatch::REVIEW, $svc->match(['member_number' => 'MBR-1999-999999'])->outcome);
        $this->assertSame('conflict', $svc->match(['email' => 'budi@example.com', 'member_number' => $andi->member_number])->reason);

        $this->self($budi, $this->plc);
        $this->assertSame(IdentityMatch::DUPLICATE, $svc->match(['email' => 'budi@example.com'], null, $this->plc)->outcome);
    }

    public function test_unknown_participants_go_to_review_queue_without_creating_accounts(): void
    {
        $users = User::count();
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT ABC Manufaktur', 'consent' => true,
            'participants_count' => 2, 'participants' => [['name' => 'Siti', 'email' => 'siti@abc.co.id'], ['name' => 'Tanpa Email']]])->assertCreated();
        [$siti, $anon] = app(EnrollmentService::class)->fromRegistration(Registration::query()->latest('id')->firstOrFail());

        $this->assertSame($users, User::count(), 'tidak ada akun dibuat otomatis');
        $this->assertNull($siti->member_id);
        $this->assertSame(['not_found', 'incomplete'], IdentityReview::query()->orderBy('id')->pluck('reason')->all());

        $svc = app(IdentityMatchingService::class);
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $review = IdentityReview::query()->where('subject_id', $siti->id)->firstOrFail();
        $svc->createMemberForReview($review, $admin);
        $this->assertNotNull($siti->fresh()->member_id);
        $this->assertSame('created', $review->fresh()->resolution);

        // menautkan peserta lain ke member yang sama di batch yang sama → duplikat ditolak
        $this->expectException(ValidationException::class);
        $svc->linkReview(IdentityReview::query()->where('subject_id', $anon->id)->firstOrFail(), $siti->fresh()->member, $admin);
    }

    public function test_identity_backfill_command_links_confident_matches_only(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $this->member('Andi', 'andi@example.com', false);
        $e1 = Enrollment::query()->create(['schedule_id' => $this->plc->id, 'participant_name' => 'Budi', 'participant_email' => 'budi@example.com']);
        $e2 = Enrollment::query()->create(['schedule_id' => $this->plc->id, 'participant_name' => 'Andi', 'participant_email' => 'andi@example.com']);

        $this->artisan('identity:backfill')->expectsOutputToContain('Ditautkan otomatis: 1')->assertSuccessful();
        $this->assertSame($budi->id, $e1->fresh()->member_id);
        $this->assertNull($e2->fresh()->member_id);
        $this->assertDatabaseHas('identity_reviews', ['subject_id' => $e2->id, 'reason' => 'unverified_email', 'status' => 'pending']);

        $this->artisan('identity:backfill')->assertSuccessful();
        $this->assertSame(1, IdentityReview::query()->where('subject_id', $e2->id)->count(), 'aman diulang: tidak menggandakan review');
    }

    // ---------- backfill migrasi ----------

    public function test_sponsorship_backfill_for_legacy_data(): void
    {
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@lama.co.id', 'phone' => '0811', 'company' => 'CV Lama Jaya', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'P1']]])->assertCreated();
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'individual', 'name' => 'Indiv', 'email' => 'indiv@example.com', 'phone' => '0812', 'consent' => true])->assertCreated();
        foreach (Registration::all() as $r) {
            app(EnrollmentService::class)->fromRegistration($r);
        }
        // kembalikan ke bentuk data sebelum Roadmap 5
        DB::table('enrollments')->update(['type' => 'self', 'sponsor_organization_id' => null]);
        DB::table('registrations')->update(['organization_id' => null]);
        $legacyOrg = Organization::query()->where('normalized_name', 'lama jaya')->firstOrFail();
        DB::table('organizations')->where('id', $legacyOrg->id)->delete();

        $stats = SponsorshipBackfill::run();

        $org = Organization::query()->where('normalized_name', 'lama jaya')->firstOrFail();
        $this->assertTrue($org->needs_review);
        $this->assertSame('backfill', $org->source);
        $this->assertSame(['organizations' => 1, 'registrations' => 1, 'enrollments' => 1], $stats);
        $this->assertSame(1, Enrollment::query()->visibleToOrganization($org)->count());
        $this->assertSame(1, Enrollment::query()->selfFunded()->count());
        $this->assertSame(['organizations' => 0, 'registrations' => 0, 'enrollments' => 0], SponsorshipBackfill::run(), 'idempoten');
    }

    // ---------- §20.20 transfer sponsor ----------

    public function test_sponsorship_transfer_workflow_and_immutable_audit(): void
    {
        $svc = app(SponsorshipService::class);
        $budi = $this->member('Budi', 'budi@example.com');
        $plc = $this->corporate($budi, $this->abc, $this->plc);
        $requester = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $approver = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);
        $this->seed(RolesSeeder::class);

        $t = $svc->request($plc, $this->xyz, today(), 'Biaya resmi dialihkan ke PT XYZ', 'Memo 12/XI/2026', null, $requester);

        foreach ([[$requester, 'peminta tidak boleh menyetujui sendiri'], [User::factory()->create(['role' => User::ROLE_MARKETING]), 'tanpa izin sponsorship.approve']] as [$who, $why]) {
            try {
                $svc->approve($t, $who);
                $this->fail($why);
            } catch (ValidationException) {
            }
        }

        $svc->approve($t, $approver, 'Disetujui');
        $this->assertSame('applied', $t->fresh()->status);
        $this->assertSame($this->xyz->id, $plc->fresh()->sponsor_organization_id);
        $this->assertSame([], $this->visibleIds($this->hr($this->abc), $this->abc));
        $this->assertSame([$plc->id], $this->visibleIds($this->hr($this->xyz), $this->xyz));

        $audit = AuditLog::query()->where('action', 'enrollment.sponsor_transferred')->firstOrFail();
        $this->assertSame($this->abc->id, $audit->before['sponsor_organization_id']);
        $this->assertSame('Biaya resmi dialihkan ke PT XYZ', $audit->after['reason']);

        foreach ([fn () => $audit->update(['action' => 'x']), fn () => $audit->delete(), fn () => $t->fresh()->update(['reason' => 'ubah']), fn () => $t->fresh()->delete()] as $tamper) {
            try {
                $tamper();
                $this->fail('catatan final tidak boleh diubah/dihapus');
            } catch (LogicException) {
            }
        }
    }

    public function test_future_dated_transfer_applies_on_effective_date(): void
    {
        $svc = app(SponsorshipService::class);
        $plc = $this->corporate($this->member('Budi', 'budi@example.com'), $this->abc, $this->plc);
        $approver = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);

        $t = $svc->request($plc, null, '2026-12-01', 'Ternyata dibayar pribadi');
        $svc->approve($t, $approver);
        $this->assertSame('approved', $t->fresh()->status);
        $this->assertSame($this->abc->id, $plc->fresh()->sponsor_organization_id, 'belum berlaku sebelum tanggal efektif');

        $this->travelTo(Carbon::parse('2026-12-01 01:00'));
        $this->artisan('sponsorship:apply-due')->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame(Enrollment::TYPE_SELF, $plc->fresh()->type);
        $this->assertNull($plc->fresh()->sponsor_organization_id);
    }

    public function test_registration_status_flow_still_creates_sponsored_enrollments(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->postJson('/api/v1/registrations', ['schedule' => $this->plc->code, 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT ABC Manufaktur', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'P1', 'email' => 'p1@abc.co.id']]])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $svc = app(RegistrationService::class);
        foreach ([RegistrationStatus::UnderReview, RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification, RegistrationStatus::Confirmed] as $to) {
            $svc->transition($reg->fresh(), $to, $admin);
        }
        $this->assertSame($this->abc->id, $reg->fresh()->organization_id);
        $this->assertSame(1, Enrollment::query()->visibleToOrganization($this->abc)->count());
    }
}
