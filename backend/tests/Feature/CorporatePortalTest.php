<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\IdentityReview;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\ParticipantInvitation;
use App\Models\Registration;
use App\Models\RequestSeat;
use App\Models\TrainingLogbook;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\ParticipantInvited;
use App\Notifications\TrainingRequestUpdated;
use App\Notifications\VerifyEmailNotification;
use App\Services\EmploymentService;
use App\Services\EnrollmentService;
use App\Services\IdentityMatchingService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\RegistrationService;
use App\Services\TrainingRequestService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Roadmap 5 tahap 5B: verifikasi email, Corporate Training Request, kursi, penugasan peserta, portal perusahaan. */
class CorporatePortalTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationService $orgs;

    private Organization $abc;

    private TrainingSchedule $schedule;

    private User $hr;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        $this->orgs = app(OrganizationService::class);
        $this->abc = $this->orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        $this->hr = User::factory()->create(['name' => 'Hana HR', 'email' => 'hana@abc.co.id']);
        $this->orgs->addCorporateUser($this->abc, $this->hr, 'hr_admin');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/corporate/organizations/{$this->abc->code}".$path;
    }

    private function member(string $name, string $email, bool $verified = true): Member
    {
        $u = User::factory()->create(['name' => $name, 'email' => $email, 'email_verified_at' => $verified ? now() : null]);

        return app(MemberService::class)->createForUser($u)->load('user');
    }

    private function newRequest(int $seats = 3, array $extra = []): TrainingRequest
    {
        $code = $this->actingAs($this->hr, 'sanctum')->postJson($this->url('/requests'), [
            'package_id' => $this->schedule->package_id, 'seats' => $seats, 'delivery' => 'onsite', 'target_date' => '2026-12-01', 'location' => 'Batam', 'submit' => true,
        ] + $extra)->assertCreated()->json('data.code');

        return TrainingRequest::query()->where('code', $code)->firstOrFail();
    }

    private function seat(TrainingRequest $r, int $no = 1): RequestSeat
    {
        return $r->requestSeats()->where('seat_no', $no)->firstOrFail();
    }

    private function toPaidAndScheduled(TrainingRequest $r): TrainingRequest
    {
        $svc = app(TrainingRequestService::class);
        $svc->quote($r, $this->admin, 7000000, 1000000, '2026-11-30', 'Harga korporat');
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/accept-quote"))->assertOk();
        $svc->markPaid($r->refresh(), $this->admin, 'TRF-001');
        $svc->schedule($r->refresh(), $this->schedule, $this->admin);

        return $r->refresh();
    }

    // ---------- verifikasi email ----------

    public function test_register_sends_verification_and_signed_link_verifies(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Rina', 'email' => 'rina@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'phone' => '0812', 'consent' => true])->assertCreated()
            ->assertJsonPath('user.emailVerified', false);
        $user = User::query()->where('email', 'rina@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        parse_str((string) parse_url(VerifyEmailNotification::url($user), PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature=bad")->assertForbidden();
        $this->getJson("/api/v1/auth/email/verify/{$q['id']}/".sha1('lain@example.com')."?expires={$q['expires']}&signature={$q['signature']}")->assertForbidden();
        $this->getJson("/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature={$q['signature']}")->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk()->assertJsonPath('message', 'Email sudah terverifikasi.');
    }

    public function test_email_verification_links_pending_identity_reviews(): void
    {
        $siti = $this->member('Siti', 'siti@abc.co.id', verified: false);
        $e = Enrollment::query()->create(['schedule_id' => $this->schedule->id, 'participant_name' => 'Siti', 'participant_email' => 'siti@abc.co.id']);
        app(IdentityMatchingService::class)->resolveEnrollment($e);
        $this->assertNull($e->fresh()->member_id, 'email belum terverifikasi → tidak ditautkan otomatis');

        parse_str((string) parse_url(VerifyEmailNotification::url($siti->user), PHP_URL_QUERY), $q);
        $this->getJson("/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature={$q['signature']}")->assertOk();

        $this->assertSame($siti->id, $e->fresh()->member_id);
        $this->assertSame('resolved', IdentityReview::query()->where('subject_id', $e->id)->value('status'));
    }

    // ---------- request lifecycle ----------

    public function test_request_reserves_seats_without_creating_members_and_follows_status_flow(): void
    {
        $members = Member::count();
        $r = $this->newRequest(10);
        $this->assertSame('submitted', $r->status);
        $this->assertSame(10, $r->requestSeats()->where('status', 'reserved')->count());
        $this->assertSame($members, Member::count(), 'kursi tidak membuat Member (AC §20.22)');
        Notification::assertSentTo(User::query()->where('role', User::ROLE_ADMINISTRATOR)->get(), TrainingRequestUpdated::class);

        $svc = app(TrainingRequestService::class);
        $svc->quote($r, $this->admin, 7500000, 5000000, '2026-11-30');
        $this->assertSame(70000000.0, (float) $r->fresh()->quote_total);
        Notification::assertSentTo($this->hr, TrainingRequestUpdated::class);
        $this->actingAs($this->hr, 'sanctum')->getJson($this->url("/requests/{$r->code}"))->assertOk()->assertJsonPath('data.quote.total', 70000000)->assertJsonPath('data.can.acceptQuote', true);

        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/accept-quote"))->assertOk()->assertJsonPath('data.status', 'approved');
        $svc->markPaid($r->refresh(), $this->admin, 'TRF-77');
        $svc->schedule($r->refresh(), $this->schedule, $this->admin);
        $this->assertSame('scheduled', $r->fresh()->status);

        $this->travelTo(Carbon::parse($this->schedule->start_date)->addHour());
        $this->artisan('requests:sync-status')->assertSuccessful();
        $this->assertSame('in_progress', $r->fresh()->status);
        $this->travelTo(Carbon::parse($this->schedule->end_date)->addDay());
        $this->artisan('requests:sync-status')->assertSuccessful();
        $this->assertSame('completed', $r->fresh()->status);
        $this->assertGreaterThanOrEqual(6, AuditLog::query()->where('auditable_type', TrainingRequest::class)->count());
    }

    public function test_optional_company_approval(): void
    {
        $this->abc->update(['settings' => ['requires_approval' => true]]);
        $owner = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $owner, 'owner');
        $r = $this->newRequest();
        app(TrainingRequestService::class)->quote($r, $this->admin, 1000000);

        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/accept-quote"))->assertOk()->assertJsonPath('data.status', 'approval');
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/approve"))->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')->postJson($this->url("/requests/{$r->code}/approve"), ['note' => 'Anggaran Q1'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_roles_and_scope_for_requests(): void
    {
        $viewer = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $viewer, 'viewer');
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/requests'), ['package_id' => $this->schedule->package_id, 'seats' => 2, 'delivery' => 'online'])->assertUnprocessable();

        $eng = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Engineering']);
        $hrd = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'HRD']);
        $mine = $this->newRequest(2, ['unit_id' => $eng->id]);
        $other = $this->newRequest(2, ['unit_id' => $hrd->id]);
        $sup = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $sup, 'supervisor', $eng);
        $codes = collect($this->actingAs($sup, 'sanctum')->getJson($this->url('/requests'))->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame([$mine->code], $codes);
        $this->actingAs($sup, 'sanctum')->getJson($this->url("/requests/{$other->code}"))->assertNotFound();
        $this->assertNull($this->actingAs($sup, 'sanctum')->getJson($this->url("/requests/{$mine->code}"))->json('data.quote'));

        $xyz = $this->orgs->create(['legal_name' => 'PT XYZ']);
        $this->actingAs($this->hr, 'sanctum')->getJson("/api/v1/corporate/organizations/{$xyz->code}/requests/{$mine->code}")->assertNotFound();
        $otherSeat = $this->seat($other);
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$mine->code}/seats/{$otherSeat->id}/assign"), ['name' => 'X', 'email' => 'x@x.id'])->assertNotFound();
    }

    // ---------- penugasan peserta ----------

    public function test_assign_verified_existing_member_reuses_identity_and_enrolls_when_scheduled(): void
    {
        $budi = $this->member('Budi Santoso', 'budi@example.com');
        $users = User::count();
        $r = $this->toPaidAndScheduled($this->newRequest());

        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r)->id}/assign"), ['name' => 'Budi', 'email' => 'BUDI@example.com', 'employee_id' => 'ABC-1'])
            ->assertOk()->assertJsonPath('data.status', 'assigned');

        $seat = $this->seat($r)->fresh();
        $this->assertSame('enrolled', $seat->status);
        $this->assertSame($users, User::count());
        $e = $seat->enrollment;
        $this->assertSame($budi->id, $e->member_id);
        $this->assertSame(Enrollment::TYPE_CORPORATE, $e->type);
        $this->assertSame($this->abc->id, $e->sponsor_organization_id);
        $this->assertTrue(EmploymentRelationship::query()->active()->where('member_id', $budi->id)->where('organization_id', $this->abc->id)->where('employee_id', 'ABC-1')->exists());

        // duplikat di permintaan yang sama & di batch yang sama ditolak
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r, 2)->id}/assign"), ['name' => 'Budi', 'email' => 'budi@example.com'])->assertUnprocessable();
    }

    public function test_invitation_flow_verifies_email_and_requires_consent(): void
    {
        $r = $this->newRequest();
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r)->id}/assign"), ['name' => 'Dewi', 'email' => 'dewi@abc.co.id', 'position' => 'Operator'])
            ->assertOk()->assertJsonPath('data.status', 'invited');
        $token = null;
        $invitation = ParticipantInvitation::query()->firstOrFail();
        $this->assertSame('dewi@abc.co.id', $invitation->email);
        Notification::assertSentTo($invitation, ParticipantInvited::class, function (ParticipantInvited $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertSame(ParticipantInvitation::hashToken($token), $invitation->token_hash, 'hanya hash token yang disimpan');
        $this->assertSame('invited', $this->seat($r)->status);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.organization', 'PT ABC Manufaktur')->assertJsonPath('data.usable', true)->assertJsonMissing(['email' => 'dewi@abc.co.id']);
        $this->getJson('/api/v1/invitations/salah')->assertNotFound();

        $wrong = User::factory()->create(['email' => 'orang.lain@example.com']);
        $this->actingAs($wrong, 'sanctum')->postJson("/api/v1/invitations/{$token}/accept", ['consent' => true])->assertUnprocessable();

        $dewi = User::factory()->create(['name' => 'Dewi Lestari', 'email' => 'dewi@abc.co.id', 'email_verified_at' => null]);
        $this->actingAs($dewi, 'sanctum')->postJson("/api/v1/invitations/{$token}/accept", ['consent' => false])->assertUnprocessable();
        $this->actingAs($dewi, 'sanctum')->postJson("/api/v1/invitations/{$token}/accept", ['consent' => true])->assertOk()->assertJsonPath('data.status', 'assigned');

        $this->assertTrue($dewi->fresh()->hasVerifiedEmail(), 'membuka tautan undangan membuktikan kepemilikan email');
        $this->assertNotNull($dewi->fresh()->member);
        $this->assertSame('accepted', ParticipantInvitation::query()->value('status'));
        $this->actingAs($dewi, 'sanctum')->postJson("/api/v1/invitations/{$token}/accept", ['consent' => true])->assertUnprocessable();

        // saat batch ditetapkan, kursi berisi peserta otomatis menjadi enrollment CORPORATE
        $this->toPaidAndScheduled($r);
        $this->assertSame('enrolled', $this->seat($r)->fresh()->status);
        $this->assertSame($dewi->fresh()->member->id, $this->seat($r)->fresh()->enrollment->member_id);
    }

    public function test_unverified_match_is_invited_not_exposed_and_conflict_goes_to_review(): void
    {
        $this->member('Andi', 'andi@example.com', verified: false);
        $budi = $this->member('Budi', 'budi@example.com');
        $r = $this->newRequest();

        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r)->id}/assign"), ['name' => 'Andi', 'email' => 'andi@example.com'])
            ->assertOk()->assertJsonPath('data.status', 'invited')->assertJsonMissing(['member_number']);

        $res = $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r, 2)->id}/assign"), ['name' => 'Campur', 'email' => 'budi@example.com', 'member_number' => Member::query()->where('id', '!=', $budi->id)->value('member_number')])
            ->assertOk()->assertJsonPath('data.status', 'review');
        $this->assertSame('reserved', $this->seat($r, 2)->status);
        $review = IdentityReview::query()->where('subject_type', (new RequestSeat)->getMorphClass())->firstOrFail();
        $this->assertSame('conflict', $review->reason);

        app(IdentityMatchingService::class)->linkReview($review, $budi, $this->admin);
        $this->assertSame('assigned', $this->seat($r, 2)->fresh()->status);
        $this->assertSame($budi->id, $this->seat($r, 2)->fresh()->member_id);
    }

    public function test_assign_from_employee_list(): void
    {
        $budi = $this->member('Budi', 'budi@example.com', verified: false);
        $emp = app(EmploymentService::class)->start($budi, $this->abc, ['employee_id' => 'ABC-9']);
        $r = $this->newRequest();
        $list = $this->actingAs($this->hr, 'sanctum')->getJson($this->url('/employees'))->assertOk()->json('data');
        $this->assertSame('ABC-9', $list[0]['employeeId']);
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r)->id}/assign-employee"), ['employment_id' => $emp->id])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->assertSame($budi->id, $this->seat($r)->fresh()->member_id);
    }

    public function test_bulk_import_reports_each_row(): void
    {
        $this->member('Budi', 'budi@example.com');
        $r = $this->newRequest(3);
        $csv = "nama;email;hp;employee_id;nomor_member;departemen;jabatan\n"
            ."Budi;budi@example.com;;ABC-1;;;Teknisi\n"
            ."Citra;citra@abc.co.id;0812;ABC-2;;;Operator\n"
            ."Citra lagi;citra@abc.co.id;;;;;\n"
            .";tanpa.nama@abc.co.id;;;;;\n"
            ."Dodi;dodi@abc.co.id;;;;;\n"
            ."Eka;eka@abc.co.id;;;;;\n";
        $res = $this->actingAs($this->hr, 'sanctum')->post($this->url("/requests/{$r->code}/import"), ['file' => UploadedFile::fake()->createWithContent('peserta.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $rows = collect($res->json('data'))->keyBy('row');

        $this->assertSame('assigned', $rows[2]['status']);
        $this->assertSame('invited', $rows[3]['status']);
        $this->assertStringContainsString('Duplikat dengan baris 3', $rows[4]['message']);
        $this->assertSame('error', $rows[5]['status']);
        $this->assertSame('invited', $rows[6]['status']);
        $this->assertStringContainsString('Kursi habis', $rows[7]['message']);
        $this->assertSame(['assigned' => 1, 'invited' => 2, 'error' => 3], $res->json('summary'));

        $this->actingAs($this->hr, 'sanctum')->get($this->url("/requests/{$r->code}/import-template"))->assertOk()->assertSee('nama,email,hp,employee_id', false);
    }

    public function test_release_seat_drops_only_that_enrollment(): void
    {
        $a = $this->member('A', 'a@example.com');
        $b = $this->member('B', 'b@example.com');
        $r = $this->toPaidAndScheduled($this->newRequest());
        foreach ([[$a, 1], [$b, 2]] as [$m, $no]) {
            $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r, $no)->id}/assign"), ['name' => $m->user->name, 'email' => $m->user->email])->assertOk();
        }
        $ea = $this->seat($r, 1)->fresh()->enrollment;
        $eb = $this->seat($r, 2)->fresh()->enrollment;

        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r, 1)->id}/release"), ['reason' => 'Dinas luar'])->assertOk();

        $this->assertSame('dropped', $ea->fresh()->status);
        $this->assertSame('enrolled', $eb->fresh()->status);
        $this->assertSame('reserved', $this->seat($r, 1)->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_seat.released']);
    }

    // ---------- dashboard, progres, ekspor, pengguna ----------

    public function test_summary_progress_detail_hides_private_notes_and_export_respects_permission(): void
    {
        $budi = $this->member('Budi', 'budi@example.com');
        $r = $this->toPaidAndScheduled($this->newRequest());
        $this->actingAs($this->hr, 'sanctum')->postJson($this->url("/requests/{$r->code}/seats/{$this->seat($r)->id}/assign"), ['name' => 'Budi', 'email' => 'budi@example.com'])->assertOk();
        $e = $this->seat($r)->fresh()->enrollment;
        $lb = TrainingLogbook::query()->create(['schedule_id' => $this->schedule->id, 'date' => $this->schedule->start_date, 'topic' => 'Routing dasar', 'status' => 'validated', 'notes' => 'RAHASIA-INTERNAL', 'observations' => 'RAHASIA-KELAS']);
        $lb->entries()->create(['enrollment_id' => $e->id, 'practice_status' => 'completed', 'observation' => 'RAHASIA-PESERTA']);

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url())->assertOk()->assertJsonPath('data.stats.participants', 1)->assertJsonPath('data.stats.seatsBought', 3)->assertJsonPath('data.stats.seatsFilled', 1)
            ->assertJsonPath('data.me.role', 'hr_admin');
        $detail = $this->actingAs($this->hr, 'sanctum')->getJson($this->url("/enrollments/{$e->id}"))->assertOk();
        $detail->assertJsonPath('data.notes.0.topic', 'Routing dasar')->assertJsonPath('data.practice.completed', 1);
        $this->assertStringNotContainsString('RAHASIA', $detail->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate.participant_progress_viewed']);

        $csv = $this->actingAs($this->hr, 'sanctum')->get($this->url('/enrollments-export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Budi', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'corporate.exported']);

        $finance = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $finance, 'finance');
        $this->actingAs($finance, 'sanctum')->getJson($this->url('/enrollments'))->assertForbidden();
        $this->actingAs($finance, 'sanctum')->get($this->url('/enrollments-export'))->assertForbidden();
        $this->actingAs($finance, 'sanctum')->getJson($this->url("/enrollments/{$e->id}"))->assertNotFound();
        $this->assertNotNull($this->actingAs($finance, 'sanctum')->getJson($this->url("/requests/{$r->code}"))->json('data.quote'));

        $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.corporate.0.code', $this->abc->code);
    }

    public function test_owner_manages_company_users(): void
    {
        $owner = User::factory()->create();
        $ownerCu = $this->orgs->addCorporateUser($this->abc, $owner, 'owner');
        $new = User::factory()->create(['email' => 'baru@abc.co.id']);

        $this->actingAs($this->hr, 'sanctum')->getJson($this->url('/users'))->assertForbidden();
        $this->actingAs($owner, 'sanctum')->postJson($this->url('/users'), ['email' => 'tidak.ada@abc.co.id', 'role' => 'viewer'])->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')->postJson($this->url('/users'), ['email' => 'BARU@abc.co.id', 'role' => 'viewer'])->assertCreated();
        $cu = CorporateUser::query()->where('user_id', $new->id)->firstOrFail();
        $this->actingAs($owner, 'sanctum')->patchJson($this->url("/users/{$cu->id}"), ['role' => 'finance'])->assertOk();
        $this->assertSame('finance', $cu->fresh()->role);
        $this->actingAs($owner, 'sanctum')->patchJson($this->url("/users/{$ownerCu->id}"), ['role' => 'viewer'])->assertUnprocessable();
        $this->actingAs($owner, 'sanctum')->postJson($this->url("/users/{$cu->id}/disable"))->assertOk();
        $this->assertSame('disabled', $cu->fresh()->status);
        $this->assertSame(3, AuditLog::query()->where('action', 'like', 'corporate_user.%')->where('auditable_id', $cu->id)->count());
    }

    // ---------- pendaftaran kolektif publik ----------

    public function test_corporate_registration_must_be_linked_before_confirmation(): void
    {
        $this->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'corporate', 'name' => 'HR', 'email' => 'hr@abc.co.id', 'phone' => '0811', 'company' => 'PT ABC', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'P1', 'email' => 'p1@abc.co.id']]])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $svc = app(RegistrationService::class);
        foreach ([RegistrationStatus::UnderReview, RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification] as $to) {
            $svc->transition($reg->fresh(), $to, $this->admin);
        }
        try {
            $svc->transition($reg->fresh(), RegistrationStatus::Confirmed, $this->admin);
            $this->fail('konfirmasi tanpa organisasi harus ditolak');
        } catch (ValidationException) {
        }
        $reg->update(['organization_id' => $this->abc->id]);
        $svc->transition($reg->fresh(), RegistrationStatus::Confirmed, $this->admin);
        $this->assertSame(1, Enrollment::query()->visibleToOrganization($this->abc)->count());
        $this->assertNull(app(EnrollmentService::class)->fromRegistration($reg->fresh())[0]->member_id);
    }
}
