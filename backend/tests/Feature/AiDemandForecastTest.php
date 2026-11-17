<?php

namespace Tests\Feature;

use App\Filament\Widgets\Executive\DemandForecastTable;
use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Organization;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiDemandForecastService;
use App\Services\AiReadiness;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Support\Ai\Evaluators\DemandForecastEvaluator;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prakiraan permintaan (statistik, tanpa LLM): deret bulanan lengkap per total/segmen/bidang/program, prakiraan 3 bulan
 * dengan rentang, satu insight yang langsung tampil untuk staf, widget Dashboard Eksekutif, evaluasi MAE/MAPE vs baseline.
 */
class AiDemandForecastTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Member $member;

    private Organization $org;

    /** @var array<string, TrainingSchedule> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);

        $oto = TrainingCategory::query()->create(['name' => 'Otomasi Industri', 'slug' => 'otomasi', 'is_active' => true]);
        $net = TrainingCategory::query()->create(['name' => 'Jaringan', 'slug' => 'jaringan', 'is_active' => true]);
        foreach ([['plc', 'PLC Dasar', $oto], ['ccna', 'Cisco CCNA', $net]] as [$slug, $title, $cat]) {
            $p = TrainingPackage::query()->create(['slug' => $slug, 'title' => $title, 'status' => 'published', 'duration_hours' => 16, 'category_id' => $cat->id]);
            $this->s[$slug] = TrainingSchedule::query()->create(['package_id' => $p->id, 'code' => strtoupper($slug).'-X', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'status' => 'registration_open', 'quota' => 50]);
        }
        $u = User::factory()->create(['name' => 'Budi Rahasia']);
        $this->member = app(MemberService::class)->createForUser($u);
        $this->org = app(OrganizationService::class)->create(['legal_name' => 'PT Sponsor']);

        // PLC (mandiri): 2 per bulan Mei-Okt. CCNA (corporate): 1 per bulan Jul-Okt. November (bulan berjalan) tidak dihitung.
        foreach (['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $m) {
            $this->enroll('plc', "$m-03", Enrollment::TYPE_SELF);
            $this->enroll('plc', "$m-17", Enrollment::TYPE_SELF);
            if ($m >= '2026-07') {
                $this->enroll('ccna', "$m-10", Enrollment::TYPE_CORPORATE);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enroll(string $slug, string $date, string $type): void
    {
        Enrollment::query()->create(['schedule_id' => $this->s[$slug]->id, 'member_id' => $this->member->id, 'participant_name' => 'Budi Rahasia',
            'status' => 'enrolled', 'type' => $type, 'sponsor_organization_id' => $type === Enrollment::TYPE_CORPORATE ? $this->org->id : null, 'enrolled_at' => "$date 09:00:00"]);
    }

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.use_cases.demand_forecast.enabled' => true]);
    }

    public function test_off_by_default(): void
    {
        $this->assertNull(app(AiDemandForecastService::class)->run());
        $this->artisan('ai:demand-forecast')->expectsOutputToContain('belum tersedia')->assertSuccessful();
        $this->actingAs($this->admin);
        Livewire::test(DemandForecastTable::class)->assertOk()->assertSee('Belum tersedia');
        $this->assertSame(0, AiInsight::count());
    }

    public function test_monthly_series_use_complete_months_and_fill_gaps(): void
    {
        $d = app(AiDemandForecastService::class)->series();
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], $d['months']);
        $by = collect($d['series'])->keyBy(fn ($s) => $s['scope'].':'.$s['label']);
        $this->assertSame([2, 2, 3, 3, 3, 3], $by['total:Semua program']['values']);
        $this->assertSame([2, 2, 2, 2, 2, 2], $by['segment:'.Enrollment::TYPES['self']]['values']);
        $this->assertSame([0, 0, 1, 1, 1, 1], $by['segment:'.Enrollment::TYPES['corporate']]['values']);
        $this->assertSame([0, 0, 1, 1, 1, 1], $by['category:Jaringan']['values']);
        $this->assertSame([2, 2, 2, 2, 2, 2], $by['package:PLC Dasar']['values']);
        $this->assertSame(['total', 'segment', 'segment', 'category', 'category', 'package', 'package'], array_column($d['series'], 'scope'));
    }

    public function test_run_stores_one_staff_insight_with_ranges_and_replaces_the_previous(): void
    {
        $this->enable();
        $writes = [];
        $official = ['enrollments', 'registrations', 'training_schedules', 'training_packages', 'invoices', 'members'];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b('.implode('|', $official).')\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->artisan('ai:demand-forecast')->expectsOutputToContain('2026-11, 2026-12, 2027-01')->assertSuccessful();
        $first = AiInsight::query()->sole();
        $second = app(AiDemandForecastService::class)->run();
        $this->assertSame([], $writes, 'prakiraan tidak boleh menulis data resmi');

        $this->assertSame(AiInsight::STATUS_EXPIRED, $first->fresh()->status);
        $this->assertSame(AiInsight::STATUS_APPROVED, $second->status);
        $this->assertNull($second->subject_type);
        $this->assertNull($second->organization_id);
        $this->assertStringStartsWith('statistical:', $second->model_version);
        $this->assertSame('2027-01-01 00:00:00', $second->expires_at->toDateTimeString());
        $o = $second->output;
        $this->assertSame(['2026-11', '2026-12', '2027-01'], $o['months']);
        $this->assertSame(6, $o['history_months']);
        $this->assertFalse($o['data_ready']);
        $this->assertCount(7, $o['series']);
        $total = $o['series'][0];
        $this->assertSame(['2026-05' => 2, '2026-06' => 2, '2026-07' => 3, '2026-08' => 3, '2026-09' => 3, '2026-10' => 3], $total['recent']);
        // 3 bulan uji terakhir datar (3, 3, 3): "sama dengan bulan lalu" galatnya 0, lebih baik dari rata-rata 3 bulan.
        $this->assertSame('naive', $total['method']);
        $this->assertEquals(0.0, $total['backtest']['mae']);
        $this->assertEquals(3.0, $total['points'][0]['value']);
        $this->assertLessThanOrEqual($total['points'][0]['value'], $total['points'][0]['low']);
        $this->assertNull($total['backtest']['beats_baseline']);
        $this->assertStringNotContainsString('Budi', json_encode($o));
        $this->assertSame(app(AiDemandForecastService::class)->latest()->id, $second->id);
    }

    public function test_executive_widget_shows_forecast_and_records_feedback(): void
    {
        $this->enable();
        $insight = app(AiDemandForecastService::class)->run();
        $this->actingAs($this->admin);
        Livewire::test(DemandForecastTable::class)->assertOk()
            ->assertSee('Prakiraan peserta 3 bulan ke depan')
            ->assertSee('Prakiraan, dibuat AI')
            ->assertSee('Nov 2026')
            ->assertSee('Cisco CCNA')
            ->assertSee('Data belum cukup')
            ->assertSee('Belum dapat dinilai')
            ->callTableAction('helpful');
        $this->assertTrue(AiInsightFeedback::query()->where('ai_insight_id', $insight->id)->where('user_id', $this->admin->id)->sole()->helpful);
        $this->get('/admin/dashboard-eksekutif')->assertOk();

        // Fitur dimatikan: prakiraan lama tidak ditampilkan.
        config(['ai.use_cases.demand_forecast.enabled' => false]);
        Livewire::test(DemandForecastTable::class)->assertOk()->assertSee('Belum tersedia')->assertDontSee('Cisco CCNA');
    }

    public function test_readiness_evaluation_and_too_little_history(): void
    {
        $this->enable();
        Storage::fake('local');
        $check = app(AiReadiness::class)->report('demand_forecast')['checks'][0];
        $this->assertSame(['enrollment_months', 7, 24, false], [$check['metric'], $check['actual'], $check['required'], $check['ok']]);

        $r = (new DemandForecastEvaluator(app(AiDemandForecastService::class)))->evaluate();
        $this->assertSame(5, $r['metrics']['series']);              // total, 2 segmen, 2 bidang (program tidak ikut evaluasi gabungan)
        $this->assertSame('belum dapat dinilai', $r['metrics']['beats_baseline']);
        $this->assertNotNull($r['metrics']['mae']);
        $this->assertNotEmpty(array_filter($r['notes'], fn ($n) => str_contains($n, 'lebih dari 12 bulan')));
        $this->artisan('ai:evaluate', ['usecase' => 'demand_forecast'])->assertSuccessful();

        // Riwayat 3 bulan lengkap saja: tidak ada prakiraan.
        Carbon::setTestNow('2026-08-05 10:00:00');
        $this->assertNull(app(AiDemandForecastService::class)->run());
        $this->artisan('ai:demand-forecast')->expectsOutputToContain('belum cukup')->assertSuccessful();
    }
}
