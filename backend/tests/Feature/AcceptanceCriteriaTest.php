<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\TrainingPackages\Pages\CreateTrainingPackage;
use App\Filament\Resources\TrainingPackages\Pages\EditTrainingPackage;
use App\Filament\Resources\TrainingSchedules\Pages\CreateTrainingSchedule;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Registration;
use App\Models\TrainingPackage;
use App\Models\User;
use App\Services\CompletionService;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kriteria penerimaan PRD v1.0 bagian 12 yang belum punya tes ujung-ke-ujung tersendiri. Kriteria lain dibuktikan tes
 * yang sudah ada; peta lengkapnya ada di task.md ("Kriteria penerimaan PRD v1.0").
 */
class AcceptanceCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** AC-01: admin membuat dan mengubah paket; data tersimpan dan tampil sesuai status publikasi. */
    public function test_ac01_admin_creates_and_updates_package_visible_only_when_published(): void
    {
        $this->actingAs($this->admin)->get('/admin/training-packages/create')->assertOk();
        Livewire::test(CreateTrainingPackage::class)
            ->fillForm([
                'title' => 'PLC Siemens Dasar', 'slug' => 'plc-siemens-dasar', 'level' => 'Fundamental', 'duration_days' => 3,
                'mode' => 'offline', 'price' => 4500000, 'overview_intro' => [['p' => 'Pengantar pemrograman PLC Siemens.']], 'program_type' => 'regular', 'certificate_type' => 'participation',
                'status' => 'draft', 'availability' => 'available',
            ])
            ->call('create')->assertHasNoFormErrors();
        $package = TrainingPackage::query()->where('slug', 'plc-siemens-dasar')->firstOrFail();
        $this->assertSame('draft', $package->status);

        // draft tidak tampil di katalog publik
        $this->getJson('/api/v1/packages/plc-siemens-dasar')->assertNotFound();
        $this->assertNotContains('plc-siemens-dasar', collect($this->getJson('/api/v1/packages')->json('data'))->pluck('slug'));

        // diubah & diterbitkan → tampil dengan data terbaru
        Livewire::test(EditTrainingPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm(['title' => 'PLC Siemens S7 Dasar', 'status' => 'published', 'price' => 4750000])
            ->call('save')->assertHasNoFormErrors();
        $this->getJson('/api/v1/packages/plc-siemens-dasar')->assertOk()
            ->assertJsonPath('data.title', 'PLC Siemens S7 Dasar')->assertJsonPath('data.price', 4750000);
        $this->assertContains('plc-siemens-dasar', collect($this->getJson('/api/v1/packages')->json('data'))->pluck('slug'));

        // diarsipkan → hilang lagi dari publik, data tetap tersimpan
        $package->refresh()->update(['status' => 'archived']);
        $this->getJson('/api/v1/packages/plc-siemens-dasar')->assertNotFound();
        $this->assertDatabaseHas('training_packages', ['slug' => 'plc-siemens-dasar', 'title' => 'PLC Siemens S7 Dasar']);
    }

    /** AC-02: admin membuat jadwal dari paket; jadwal terhubung ke paket dan kapasitas dapat diatur. */
    public function test_ac02_admin_creates_schedule_from_package_with_capacity(): void
    {
        $package = TrainingPackage::query()->where('slug', 'cisco-ccna')->firstOrFail();
        $this->actingAs($this->admin)->get('/admin/training-schedules/create')->assertOk();
        Livewire::test(CreateTrainingSchedule::class)
            ->fillForm([
                'package_id' => $package->id, 'code' => 'CCNA-2702', 'start_date' => '2027-02-08', 'end_date' => '2027-02-12',
                'mode' => 'offline', 'city' => 'Batam', 'quota' => 12, 'registered_count' => 0, 'status' => 'registration_open',
            ])
            ->call('create')->assertHasNoFormErrors();

        $this->getJson('/api/v1/schedules/CCNA-2702')->assertOk()
            ->assertJsonPath('data.courseSlug', 'cisco-ccna')
            ->assertJsonPath('data.quota', 12)
            ->assertJsonPath('data.remaining', 12);
        $this->assertContains('CCNA-2702', collect($this->getJson('/api/v1/packages/cisco-ccna')->json('data.schedules'))->pluck('code'));
    }

    /** AC-11: alumni mendaftar lagi dengan akun yang sama; riwayat lama tetap tersedia. */
    public function test_ac11_alumni_registers_again_with_same_account_and_keeps_history(): void
    {
        $user = User::factory()->create(['email' => 'alumni@example.com', 'name' => 'Alumni Satu']);
        $payload = fn (string $code) => ['schedule' => $code, 'type' => 'individual', 'name' => $user->name, 'email' => $user->email, 'phone' => '0812', 'consent' => true];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/registrations', $payload('CCNA-2611'))->assertCreated();
        $this->app['auth']->forgetGuards();
        $first = Registration::query()->latest('id')->firstOrFail();
        $svc = app(RegistrationService::class);
        foreach ([RegistrationStatus::WaitingPayment, RegistrationStatus::PaymentVerification, RegistrationStatus::Confirmed] as $to) {
            $svc->transition($first, $to, $this->admin);
        }
        $enrollment = Enrollment::query()->where('registration_id', $first->id)->firstOrFail();
        app(CompletionService::class)->complete($enrollment, $this->admin, pass: true, finalScore: 85, note: 'Lulus');
        $memberId = $user->refresh()->member->id;
        $this->assertSame('alumni', $user->member->status);
        $members = Member::query()->count();

        // daftar program baru dengan akun yang sama
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/registrations', $payload('MTCNA-2611'))->assertCreated();
        $second = Registration::query()->latest('id')->firstOrFail();
        $this->assertSame($memberId, $second->member_id, 'pendaftaran baru terhubung ke member yang sama');
        $this->assertSame($members, Member::query()->count(), 'tidak ada member ganda');

        // riwayat lama tetap ada di portal
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/registrations')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/enrollments')->assertOk()
            ->assertJsonFragment(['status' => 'completed']);
        $this->assertSame('alumni', $user->member->refresh()->status, 'status alumni tetap setelah mendaftar lagi');
    }
}
