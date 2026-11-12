<?php

namespace Tests\Feature;

use App\Filament\Resources\CorporateUsers\Pages\ManageCorporateUsers;
use App\Filament\Resources\Employments\Pages\ManageEmployments;
use App\Filament\Resources\IdentityReviews\Pages\ManageIdentityReviews;
use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Registrations\Pages\EditRegistration;
use App\Filament\Resources\SponsorshipTransfers\Pages\ManageSponsorshipTransfers;
use App\Filament\Resources\TrainingRequests\Pages\ViewTrainingRequest;
use App\Filament\Resources\TrainingRequests\RelationManagers\SeatsRelationManager;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\IdentityReview;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Registration;
use App\Models\SponsorshipTransfer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\SponsorshipService;
use App\Services\TrainingRequestService;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel admin Roadmap 5A: menu Korporat terisi data, izin, dan aksi utama lewat Filament (Livewire). */
class EnterpriseAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $abc;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'name' => 'Admin VMES']);
        $this->seed(RolesSeeder::class);

        $orgs = app(OrganizationService::class);
        $this->abc = $orgs->create(['legal_name' => 'PT ABC Manufaktur', 'industry' => 'Manufaktur']);
        $eng = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Engineering', 'code' => 'ENG-01']);
        $budiUser = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        $budi = app(MemberService::class)->createForUser($budiUser);
        app(EmploymentService::class)->start($budi, $this->abc, ['employee_id' => 'ABC-001', 'unit_id' => $eng->id, 'position' => 'Teknisi']);
        $orgs->addCorporateUser($this->abc, User::factory()->create(['name' => 'Hana HR']), 'hr_admin', $eng);
        $schedule = TrainingSchedule::query()->firstOrFail();
        $this->enrollment = Enrollment::query()->create(['schedule_id' => $schedule->id, 'member_id' => $budi->id, 'type' => 'corporate', 'sponsor_organization_id' => $this->abc->id, 'sponsor_unit_id' => $eng->id, 'participant_name' => 'Budi Santoso']);
        IdentityReview::query()->create(['reason' => 'phone_match', 'input' => ['name' => 'Siti Aminah', 'phone' => '6281234'], 'candidates' => [['member_id' => $budi->id, 'member_number' => $budi->member_number, 'name' => 'Budi Santoso', 'matched_by' => 'phone_match']], 'status' => 'pending', 'organization_id' => $this->abc->id]);
        app(SponsorshipService::class)->request($this->enrollment, null, today()->addWeek(), 'Dibayar pribadi', 'Memo 1', null, $this->admin);
    }

    public function test_admin_pages_render_with_data(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/organizations')->assertOk()->assertSee('PT ABC Manufaktur')->assertSee('Korporat (Enterprise)');
        $this->get('/admin/organizations/create')->assertOk();
        $this->get("/admin/organizations/{$this->abc->code}/edit")->assertOk()->assertSee('Akses setelah karyawan keluar');
        $this->get('/admin/employments')->assertOk()->assertSee('ABC-001')->assertSee('Budi Santoso');
        $this->get('/admin/corporate-users')->assertOk()->assertSee('Hana HR')->assertSee('HR/L&amp;D Admin', false);
        $this->get('/admin/identity-reviews')->assertOk()->assertSee('Siti Aminah')->assertSee('Nomor HP cocok');
        $this->get('/admin/sponsorship-transfers')->assertOk()->assertSee('Dibayar pribadi')->assertSee('Mandiri (SELF)');
    }

    public function test_permissions(): void
    {
        $marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $this->actingAs($marketing)->get('/admin/organizations')->assertOk();
        $this->actingAs($marketing)->get('/admin/sponsorship-transfers')->assertOk()->assertDontSee('Setujui');

        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        foreach (['organizations', 'employments', 'corporate-users', 'identity-reviews', 'sponsorship-transfers'] as $slug) {
            $this->actingAs($finance)->get("/admin/$slug")->assertForbidden();
        }
    }

    public function test_training_request_admin_quote_pay_schedule_and_registration_link(): void
    {
        $cu = CorporateUser::query()->firstOrFail();
        $schedule = TrainingSchedule::query()->firstOrFail();
        $r = app(TrainingRequestService::class)->create($cu, ['package_id' => $schedule->package_id, 'seats' => 4, 'delivery' => 'onsite', 'target_date' => today()->addMonth()->toDateString()], submit: true);
        $this->actingAs($this->admin);

        $this->get('/admin/training-requests')->assertOk()->assertSee($r->code)->assertSee('0/4 terisi');
        $this->get("/admin/training-requests/{$r->code}")->assertOk()->assertSee('Kebutuhan trainer')->assertSee('PT ABC Manufaktur');

        Livewire::test(ViewTrainingRequest::class, ['record' => $r->code])
            ->callAction('quote', ['price_per_seat' => 5000000, 'discount' => 0, 'valid_until' => today()->addWeek()->toDateString(), 'notes' => 'Termasuk modul'])->assertHasNoActionErrors();
        $this->assertSame('quoted', $r->fresh()->status);
        $this->assertSame(20000000.0, (float) $r->fresh()->quote_total);

        Livewire::test(SeatsRelationManager::class, ['ownerRecord' => $r->fresh(), 'pageClass' => ViewTrainingRequest::class])
            ->assertOk()->assertSee('Kosong (dipesan)')->assertCanSeeTableRecords($r->requestSeats);

        // jadwal hanya bisa ditetapkan setelah lunas
        Livewire::test(ViewTrainingRequest::class, ['record' => $r->code])->assertActionHidden('schedule');

        // pendaftaran kolektif publik: tautkan organisasi lewat aksi admin
        $this->postJson('/api/v1/registrations', ['schedule' => $schedule->code, 'type' => 'corporate', 'name' => 'HR Lain', 'email' => 'hr@lain.co.id', 'phone' => '0811', 'company' => 'CV Lain Sejahtera', 'consent' => true,
            'participants_count' => 1, 'participants' => [['name' => 'P1']]])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin);
        Livewire::test(EditRegistration::class, ['record' => $reg->getRouteKey()])->callAction('organisasi', ['create' => true])->assertHasNoActionErrors();
        $this->assertSame('CV Lain Sejahtera', $reg->fresh()->organization?->legal_name);
    }

    public function test_filament_actions_go_through_services(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateOrganization::class)
            ->fillForm(['legal_name' => 'PT Baru Sejahtera', 'status' => 'active', 'settings' => ['exit_access' => 'summary']])
            ->call('create')->assertHasNoFormErrors();
        $baru = Organization::query()->where('legal_name', 'PT Baru Sejahtera')->firstOrFail();
        $this->assertMatchesRegularExpression('/^ORG-\d{4}$/', $baru->code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.created', 'auditable_id' => $baru->id]);

        Livewire::test(EditOrganization::class, ['record' => $this->abc->code])
            ->fillForm(['settings' => ['exit_access' => 'full']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('full', $this->abc->fresh()->exitAccess());

        $employment = EmploymentRelationship::query()->firstOrFail();
        Livewire::test(ManageEmployments::class)->callTableAction('end', $employment, ['reason' => 'resigned', 'end_date' => today()->toDateString()])->assertHasNoTableActionErrors();
        $this->assertSame('inactive', $employment->fresh()->status);

        $hana = CorporateUser::query()->firstOrFail();
        Livewire::test(ManageCorporateUsers::class)->callTableAction('role', $hana, ['role' => 'viewer', 'unit_id' => null])->assertHasNoTableActionErrors();
        $this->assertSame('viewer', $hana->fresh()->role);
        Livewire::test(ManageCorporateUsers::class)->callTableAction('disable', $hana, ['reason' => 'Pindah divisi'])->assertHasNoTableActionErrors();
        $this->assertSame('disabled', $hana->fresh()->status);

        $review = IdentityReview::query()->firstOrFail();
        Livewire::test(ManageIdentityReviews::class)->callTableAction('dismiss', $review, ['note' => 'Bukan peserta kami'])->assertHasNoTableActionErrors();
        $this->assertSame('dismissed', $review->fresh()->status);

        // Transfer: diajukan oleh admin ini → harus disetujui orang lain
        $transfer = SponsorshipTransfer::query()->firstOrFail();
        Livewire::test(ManageSponsorshipTransfers::class)->callTableAction('approve', $transfer, ['note' => 'ok'])->assertNotified('Tidak dapat diproses');
        $this->assertSame('requested', $transfer->fresh()->status, 'peminta tidak boleh menyetujui sendiri');

        // Tambah lewat form → service (duplikat employment aktif ditolak dengan notifikasi)
        $budi = EmploymentRelationship::query()->firstOrFail()->member;
        $xyz = app(OrganizationService::class)->create(['legal_name' => 'PT XYZ']);
        Livewire::test(ManageEmployments::class)->callAction('create', ['member_id' => $budi->id, 'organization_id' => $xyz->id, 'employee_id' => 'X-1', 'source' => 'admin'])->assertHasNoActionErrors();
        Livewire::test(ManageEmployments::class)->callAction('create', ['member_id' => $budi->id, 'organization_id' => $xyz->id, 'source' => 'admin'])->assertNotified('Tidak dapat diproses');
        $this->assertSame(1, EmploymentRelationship::query()->where('organization_id', $xyz->id)->count());
        $newUser = User::factory()->create();
        Livewire::test(ManageCorporateUsers::class)->callAction('create', ['organization_id' => $xyz->id, 'user_id' => $newUser->id, 'role' => 'owner'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('corporate_users', ['organization_id' => $xyz->id, 'user_id' => $newUser->id, 'role' => 'owner']);

        $manager = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);
        $this->seed(RolesSeeder::class);
        $this->actingAs($manager);
        Livewire::test(ManageSponsorshipTransfers::class)->callTableAction('approve', $transfer, ['note' => 'ok'])->assertHasNoTableActionErrors();
        $this->assertSame('approved', $transfer->fresh()->status);
    }
}
