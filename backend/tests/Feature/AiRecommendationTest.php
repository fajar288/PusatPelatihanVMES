<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiRecommendationEvent;
use App\Models\Competency;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Organization;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiRecommendationService;
use App\Services\CompetencyGapService;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rekomendasi training (tanpa LLM): mati bawaan, alasan bisa ditelusuri (prasyarat, gap kompetensi, pola peserta lain,
 * bidang, populer), program yang sudah diambil / prasyaratnya belum lulus tidak disarankan, rekomendasi per posisi untuk
 * perusahaan tanpa nama, impresi & klik untuk CTR, konversi & backtest, tanpa penulisan ke tabel resmi.
 */
class AiRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $budiUser;

    private Member $budi;

    private User $hr;

    private Organization $org;

    /** @var array<string, TrainingPackage> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();

        $oto = TrainingCategory::query()->create(['name' => 'Otomasi Industri', 'slug' => 'otomasi', 'is_active' => true]);
        $off = TrainingCategory::query()->create(['name' => 'Perkantoran', 'slug' => 'perkantoran', 'is_active' => true]);
        foreach ([['plc-dasar', 'PLC Dasar', $oto], ['plc-lanjutan', 'PLC Lanjutan', $oto], ['scada', 'SCADA Dasar', $oto], ['hmi', 'Desain HMI', $oto], ['excel', 'Excel Kantor', $off]] as [$slug, $title, $cat]) {
            $this->p[$slug] = TrainingPackage::query()->create(['slug' => $slug, 'title' => $title, 'status' => 'published', 'duration_hours' => 16, 'category_id' => $cat->id]);
            TrainingSchedule::query()->create(['package_id' => $this->p[$slug]->id, 'code' => strtoupper($slug).'-2612', 'start_date' => '2026-12-07', 'end_date' => '2026-12-09', 'status' => 'registration_open', 'quota' => 20]);
        }
        $this->p['plc-lanjutan']->prerequisites()->attach($this->p['plc-dasar']->id);
        $scada = Competency::query()->create(['code' => 'SCADA', 'name' => 'Sistem SCADA']);
        $this->p['scada']->competencyLinks()->attach($scada->id, ['level' => 70]);

        // Budi: lulus PLC Dasar (mandiri), bekerja sebagai Teknisi di PT ABC yang menargetkan SCADA 80.
        [$this->budiUser, $this->budi] = $this->member('Budi');
        $this->took($this->budi, 'plc-dasar', 'completed', '2026-10-01');
        $orgs = app(OrganizationService::class);
        $this->org = $orgs->create(['legal_name' => 'PT ABC Rekomendasi']);
        app(EmploymentService::class)->start($this->budi, $this->org, ['position' => 'Teknisi', 'employee_id' => 'ABC-1']);
        $this->hr = User::factory()->create(['name' => 'Hana HR']);
        $orgs->addCorporateUser($this->org, $this->hr, 'hr_admin');
        app(CompetencyGapService::class)->setTarget($this->org, 'Teknisi', $scada, 80, $this->hr);

        // 3 peserta lain: PLC Dasar lalu Desain HMI (pola "peserta yang mengikuti PLC Dasar juga mengikuti Desain HMI").
        foreach (['Ani', 'Dedi', 'Eka'] as $n) {
            [, $m] = $this->member($n);
            $this->took($m, 'plc-dasar', 'completed', '2026-09-01');
            $this->took($m, 'hmi', 'completed', '2026-10-15');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Member} */
    private function member(string $name): array
    {
        $u = User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        return [$u, app(MemberService::class)->createForUser($u)];
    }

    private function took(Member $m, string $slug, string $status, string $start): Enrollment
    {
        $s = TrainingSchedule::query()->create(['package_id' => $this->p[$slug]->id, 'code' => strtoupper($slug).'-'.uniqid(), 'start_date' => $start,
            'end_date' => Carbon::parse($start)->addDays(2)->toDateString(), 'status' => 'completed', 'quota' => 20]);

        return Enrollment::query()->create(['schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => 'Peserta', 'status' => $status,
            'type' => Enrollment::TYPE_SELF, 'enrolled_at' => $start, 'completed_at' => $status === 'completed' ? $s->end_date : null]);
    }

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.use_cases.recommendation.enabled' => true]);
    }

    public function test_off_by_default(): void
    {
        Sanctum::actingAs($this->budiUser);
        $this->getJson('/api/v1/me/recommendations')->assertOk()
            ->assertJsonPath('data.status.available', false)
            ->assertJsonPath('data.status.label', 'Belum tersedia')
            ->assertJsonPath('data.recommendations', null);
        $this->assertSame(0, AiInsight::query()->count());
    }

    public function test_member_recommendations_are_ranked_with_traceable_reasons(): void
    {
        $this->enable();
        Sanctum::actingAs($this->budiUser);
        $d = $this->getJson('/api/v1/me/recommendations')->assertOk()->json('data.recommendations');

        $this->assertTrue($d['aiGenerated']);
        $this->assertSame(['plc-lanjutan', 'scada', 'hmi'], array_column($d['items'], 'slug'));
        $this->assertContains('Anda lulus PLC Dasar; program ini kelanjutannya', $d['items'][0]['reasons']);
        $this->assertContains('Gap kompetensi Sistem SCADA 80 poin untuk posisi Teknisi', $d['items'][1]['reasons']);
        $this->assertContains('3 peserta yang mengikuti PLC Dasar juga mengikuti program ini', $d['items'][2]['reasons']);
        $this->assertSame('PLC-LANJUTAN-2612', $d['items'][0]['next']['code']);
        $this->assertNotContains('plc-dasar', array_column($d['items'], 'slug'), 'program yang sudah diluluskan tidak disarankan');

        // Satu insight per hari; memuat ulang tidak menambah insight maupun impresi.
        $this->getJson('/api/v1/me/recommendations')->assertOk();
        $this->assertSame(1, AiInsight::query()->where('use_case', 'recommendation')->count());
        $insight = AiInsight::query()->where('use_case', 'recommendation')->sole();
        $this->assertSame(AiInsight::STATUS_APPROVED, $insight->status, 'rekomendasi langsung tampil, tetap dicatat');
        $this->assertSame(3, AiRecommendationEvent::query()->where('event', 'impression')->count());
        $this->assertStringNotContainsString('Ani', json_encode($insight->output), 'pola peserta lain hanya berupa jumlah');
    }

    public function test_new_member_gets_popular_programs_but_never_unmet_prerequisites(): void
    {
        $this->enable();
        [$u] = $this->member('Cici');
        Sanctum::actingAs($u);
        $items = $this->getJson('/api/v1/me/recommendations')->assertOk()->json('data.recommendations.items');
        $this->assertSame(['plc-dasar', 'hmi'], array_column($items, 'slug'));
        $this->assertSame(['Diikuti 4 peserta'], $items[0]['reasons']);
        $this->assertNotContains('plc-lanjutan', array_column($items, 'slug'));
    }

    public function test_click_feedback_and_access(): void
    {
        $this->enable();
        Sanctum::actingAs($this->budiUser);
        $id = $this->getJson('/api/v1/me/recommendations')->json('data.recommendations.insightId');
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'scada'])->assertOk()->assertJsonPath('data.package', 'scada');
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'scada'])->assertOk();
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'excel'])->assertNotFound();
        $this->assertSame(1, AiRecommendationEvent::query()->where('event', 'click')->count());
        $this->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => true])->assertOk();
        $this->assertTrue(AiInsightFeedback::query()->sole()->helpful);

        $this->app['auth']->forgetGuards();
        [$other] = $this->member('Lain');
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'scada'])->assertNotFound();
        $this->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => false])->assertNotFound();
    }

    public function test_corporate_recommendations_per_position_without_names(): void
    {
        $this->enable();
        Sanctum::actingAs($this->hr);
        $d = $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/recommendations")->assertOk()->json('data.recommendations');
        $this->assertSame('Teknisi', $d['groups'][0]['position']);
        $this->assertSame(1, $d['groups'][0]['employees']);
        $this->assertSame('scada', $d['groups'][0]['items'][0]['slug']);
        $this->assertSame('1 karyawan posisi Teknisi di bawah target Sistem SCADA, rata-rata kurang 80 poin', $d['groups'][0]['items'][0]['reasons'][0]);
        $this->assertStringNotContainsString('Budi', json_encode($d));
        $insight = AiInsight::query()->where('use_case', 'recommendation')->sole();
        $this->assertSame($this->org->id, $insight->organization_id);
        $this->postJson("/api/v1/ai-insights/{$insight->id}/click", ['package' => 'scada'])->assertOk();

        // Organisasi lain tidak bisa melihat.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->budiUser);
        $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/recommendations")->assertNotFound();
        $this->postJson("/api/v1/ai-insights/{$insight->id}/click", ['package' => 'scada'])->assertNotFound();
    }

    public function test_evaluation_backtest_ctr_and_conversion(): void
    {
        Storage::fake('local');
        $this->enable();
        Sanctum::actingAs($this->budiUser);
        $id = $this->getJson('/api/v1/me/recommendations')->json('data.recommendations.insightId');
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'plc-lanjutan'])->assertOk();
        Carbon::setTestNow('2026-11-22 09:00:00');
        DB::table('registrations')->insert(['registration_number' => 'REG-202611-0901', 'schedule_id' => TrainingSchedule::query()->where('code', 'PLC-LANJUTAN-2612')->value('id'),
            'package_id' => $this->p['plc-lanjutan']->id, 'member_id' => $this->budi->id, 'user_id' => $this->budiUser->id, 'name' => 'Budi', 'email' => 'budi@example.com',
            'phone' => '0800', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $svc = app(AiRecommendationService::class);
        $o = $svc->online();
        $this->assertSame([3, 1, 0.3333, 1, 0.3333], [$o['impressions'], $o['clicks'], $o['ctr'], $o['conversions'], $o['conversion_rate']]);
        $this->assertSame([1, 1.0], [$o['alumni_shown'], $o['repeat_enrollment_rate']]);

        $b = $svc->backtest();
        $this->assertSame(3, $b['n'], 'tiga peserta dengan 2 program; program terakhir disembunyikan');
        $this->assertNotNull($b['hit_rate_at_3']);
        $this->assertNotNull($b['beats_baseline']);

        $this->artisan('ai:evaluate', ['usecase' => 'recommendation'])
            ->expectsOutputToContain('hit_rate_at_3')
            ->expectsOutputToContain('Data belum cukup')
            ->assertSuccessful();
    }

    public function test_recommendations_never_write_official_tables(): void
    {
        $this->enable();
        $official = ['enrollments', 'registrations', 'training_requests', 'members', 'employment_relationships', 'competency_targets', 'training_packages', 'package_prerequisites'];
        $writes = [];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b(into|from|update)?\s*"?('.implode('|', $official).')"?\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        Sanctum::actingAs($this->budiUser);
        $id = $this->getJson('/api/v1/me/recommendations')->json('data.recommendations.insightId');
        $this->postJson("/api/v1/ai-insights/$id/click", ['package' => 'scada']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/recommendations")->assertOk();
        app(AiRecommendationService::class)->backtest();
        app(AiRecommendationService::class)->online();
        $this->assertSame([], $writes);
    }

    public function test_admin_can_set_prerequisites(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);
        $this->actingAs($admin)->get("/admin/training-packages/{$this->p['plc-lanjutan']->getRouteKey()}/edit")->assertOk()->assertSee('Program prasyarat');
        $this->assertSame(['PLC Dasar'], $this->p['plc-lanjutan']->prerequisites()->pluck('title')->all());
    }
}
