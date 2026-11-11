<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Filament\Pages\ExecutiveDashboard;
use App\Filament\Widgets\Executive\AtRiskTable;
use App\Filament\Widgets\Executive\CertificateStatusChart;
use App\Filament\Widgets\Executive\ClassStatusChart;
use App\Filament\Widgets\Executive\FinanceStats;
use App\Filament\Widgets\Executive\LearningStats;
use App\Filament\Widgets\Executive\ParticipantStats;
use App\Filament\Widgets\Executive\PopularProgramsChart;
use App\Filament\Widgets\Executive\RevenueByProgramChart;
use App\Filament\Widgets\Executive\RevenueChart;
use App\Filament\Widgets\Executive\TrainerPerformanceTable;
use App\Filament\Widgets\Executive\TrendChart;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\RegistrationService;
use App\Services\ReportService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Phase 5 — Executive dashboard (PRD §5.13): indikator, filter, peserta berisiko, kinerja trainer, akses & ekspor. */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $schedule;

    private User $admin;

    private Trainer $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 09:00:00'); // kelas CCNA-2611 (17–21 Nov 2026) sedang berjalan, 3 sesi sudah lewat
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $this->trainer = Trainer::query()->create(['name' => 'Andi Trainer', 'slug' => 'andi-trainer', 'title' => 'Lead Trainer Networking']);
        $this->schedule->trainers()->attach($this->trainer->id, ['role' => 'lead']);
        foreach ([1 => '2026-11-17', 2 => '2026-11-18', 3 => '2026-11-19', 4 => '2026-11-20', 5 => '2026-11-21'] as $no => $date) {
            ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => $no, 'date' => $date, 'topic' => "Sesi $no", 'trainer_id' => $this->trainer->id]);
        }
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enroll(string $email, string $name, int $present, bool $paid): Enrollment
    {
        $member = User::factory()->create(['email' => $email, 'name' => $name]);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $name, 'email' => $email, 'phone' => '0812', 'consent' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web'); // Sanctum::actingAs mengganti guard default
        $reg = Registration::query()->latest('id')->first();
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $this->admin);
        if ($paid) {
            $reg->payments()->update(['status' => 'verified', 'verified_at' => now()]);
        }
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $this->admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $this->admin);
        $enrollment = Enrollment::query()->where('registration_id', $reg->id)->firstOrFail();
        foreach ($this->schedule->sessions()->orderBy('session_no')->get()->take($present) as $s) {
            Attendance::query()->create(['session_id' => $s->id, 'enrollment_id' => $enrollment->id, 'status' => 'present']);
        }

        return $enrollment;
    }

    /** @return array{0: Enrollment, 1: Enrollment} */
    protected function fixture(): array
    {
        return [
            $this->enroll('rajin@example.com', 'Rina Rajin', 3, true),
            $this->enroll('telat@example.com', 'Tono Tertinggal', 1, false),
        ];
    }

    public function test_metrics_cover_participants_classes_finance_learning_and_trainers(): void
    {
        $this->fixture();
        $amount = (float) Payment::query()->where('status', 'verified')->sum('amount');
        $this->assertGreaterThan(0, $amount);

        $d = app(AnalyticsService::class)->dashboard(['from' => '2026-11-01', 'to' => '2026-11-30']);

        $this->assertSame(2, $d['participants']['registrations']);
        $this->assertSame(2, $d['participants']['registrations_confirmed']);
        $this->assertEquals(100, $d['participants']['conversion_rate']);
        $this->assertSame(2, $d['participants']['active']);
        $this->assertSame($this->schedule->package_id, $d['participants']['popular_programs'][0]['package_id']);
        $this->assertSame(2, $d['participants']['popular_programs'][0]['participants']);
        $this->assertSame(2, $d['participants']['registrations_by_month']['2026-11']);

        $this->assertSame(1, $d['classes']['by_phase']['running']['count']);
        $this->assertGreaterThanOrEqual(1, $d['classes']['total']);

        $this->assertEquals($amount, $d['finance']['revenue']);
        $this->assertSame(1, $d['finance']['payments_verified']);
        $this->assertSame(2, $d['finance']['invoices']);
        $this->assertGreaterThan(0, $d['finance']['outstanding']);
        $this->assertSame(1, $d['finance']['outstanding_count']);
        $this->assertEquals($amount, $d['finance']['revenue_by_month']['2026-11']);

        // Kehadiran: 3/3 dan 1/3 sesi yang sudah lewat → rata-rata (100 + 33) / 2 = 66,5%; Tono berisiko (< 80% aturan kelulusan bawaan).
        $this->assertEquals(66.5, $d['learning']['avg_attendance']);
        $this->assertNull($d['learning']['completion_rate']);
        $risk = collect($d['learning']['at_risk']);
        $this->assertSame(['Tono Tertinggal'], $risk->pluck('name')->all());
        $this->assertStringContainsString('Kehadiran 33%', $risk->first()['reasons'][0]);

        $this->assertSame(1, $d['trainers']['assigned']);
        $this->assertSame(1, $d['trainers']['assignments']);
        $this->assertSame(3, $d['trainers']['sessions_held']);
        $row = $d['trainers']['rows'][0];
        $this->assertSame('Andi Trainer', $row['name']);
        $this->assertSame(1, $row['classes']);
        $this->assertSame(2, $row['participants']);
        $this->assertSame(5, $row['sessions_planned']);

        $this->assertSame(0, $d['certificates']['issued']);

        $flat = app(AnalyticsService::class)->flatten($d);
        foreach ($flat as $r) {
            $this->assertCount(3, $r);
        }
    }

    public function test_filters_narrow_the_scope(): void
    {
        $this->fixture();
        $svc = app(AnalyticsService::class);
        $other = TrainingSchedule::query()->where('package_id', '!=', $this->schedule->package_id)->value('package_id');

        $byPackage = $svc->dashboard(['from' => '2026-11-01', 'to' => '2026-11-30', 'package_id' => $other]);
        $this->assertSame(0, $byPackage['participants']['registrations']);
        $this->assertSame(0, $byPackage['participants']['active']);
        $this->assertSame(0.0, $byPackage['finance']['revenue']);
        $this->assertSame([], $byPackage['learning']['at_risk']);

        $byClass = $svc->dashboard(['from' => '2026-11-01', 'to' => '2026-11-30', 'schedule_id' => $this->schedule->id]);
        $this->assertSame(1, $byClass['classes']['total']);
        $this->assertSame(2, $byClass['participants']['active']);

        $byTrainer = $svc->dashboard(['from' => '2026-11-01', 'to' => '2026-11-30', 'trainer_id' => $this->trainer->id + 999]);
        $this->assertSame(0, $byTrainer['classes']['total']);

        $before = $svc->dashboard(['from' => '2026-01-01', 'to' => '2026-10-31']);
        $this->assertSame(0, $before['participants']['registrations']);

        // Normalisasi: tanggal terbalik ditukar, status tak dikenal diabaikan, default 12 bulan terakhir.
        $n = $svc->normalize(['from' => '2026-12-31', 'to' => '2026-01-01', 'status' => 'bogus']);
        $this->assertSame(['2026-01-01', '2026-12-31', null], [$n['from'], $n['to'], $n['status']]);
        $this->assertSame(['2025-12-01', '2026-11-19'], array_values(array_slice($svc->normalize(null), 0, 2)));
    }

    public function test_dashboard_page_access_and_widgets_render(): void
    {
        $this->fixture();
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $management = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);

        $this->actingAs($finance)->get('/admin/dashboard-eksekutif')->assertForbidden();
        $this->actingAs($management)->get('/admin/dashboard-eksekutif')->assertOk()->assertSee('Dashboard Eksekutif')->assertSee('Peserta berisiko');
        $this->actingAs($this->admin)->get('/admin/dashboard-eksekutif')->assertOk()->assertSee('Tono Tertinggal')->assertSee('Andi Trainer');
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertDontSee('Peserta berisiko tertinggal');

        $this->actingAs($this->admin);
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        Livewire::test(ExecutiveDashboard::class)->assertOk()->set('filters.package_id', $this->schedule->package_id)->assertOk();
        foreach ([ParticipantStats::class, FinanceStats::class, LearningStats::class, TrendChart::class, RevenueChart::class, PopularProgramsChart::class, RevenueByProgramChart::class, ClassStatusChart::class, CertificateStatusChart::class] as $w) {
            Livewire::test($w, ['pageFilters' => $filters])->assertOk();
        }
        Livewire::test(AtRiskTable::class, ['pageFilters' => $filters])->assertOk()->assertSee('Tono Tertinggal')->assertDontSee('Rina Rajin');
        Livewire::test(TrainerPerformanceTable::class, ['pageFilters' => $filters])->assertOk()->assertSee('Andi Trainer');
        Livewire::test(AtRiskTable::class, ['pageFilters' => $filters + ['schedule_id' => $this->schedule->id + 999]])->assertOk()->assertSee('Tidak ada peserta berisiko');
    }

    public function test_executive_exports_respect_filters_and_permission(): void
    {
        $this->fixture();
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $q = '?from=2026-11-01&to=2026-11-30';

        $this->get("/admin/export/executive.csv$q")->assertRedirect('/admin/login');
        $this->actingAs($finance)->get("/admin/export/executive.csv$q")->assertForbidden();
        $this->actingAs($finance)->get('/admin/export/registrations.csv')->assertOk(); // laporan biasa tetap untuk finance

        $csv = $this->actingAs($this->admin)->get("/admin/export/at-risk.csv$q");
        $csv->assertOk();
        $this->assertStringContainsString('Tono Tertinggal', $csv->streamedContent());
        $empty = $this->actingAs($this->admin)->get("/admin/export/at-risk.csv$q&schedule_id=".($this->schedule->id + 999));
        $this->assertStringNotContainsString('Tono Tertinggal', $empty->streamedContent());

        $this->actingAs($this->admin)->get("/admin/export/executive.xlsx$q")->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Andi Trainer', $this->actingAs($this->admin)->get("/admin/export/trainers.csv$q")->streamedContent());

        foreach (array_keys(ReportService::EXECUTIVE_TYPES) as $type) {
            $ds = app(ReportService::class)->dataset($type, '2026-11-01', '2026-11-30');
            foreach ($ds['rows'] as $row) {
                $this->assertCount(count($ds['headers']), $row);
            }
        }
    }
}
