<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AiUsageRecord;
use App\Models\Competency;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiCorporateInsightService;
use App\Services\AiInsightService;
use App\Services\CompetencyGapService;
use App\Services\EmploymentService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Support\Ai\AiPrompt;
use App\Support\Chatbot\ChatModel;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

/**
 * Insight perusahaan (corporate_insight): hanya angka gabungan ke model (kelompok kecil tidak dirinci, tanpa nama),
 * selalu draf sampai disetujui admin VMES, hanya untuk pengguna perusahaan tingkat organisasi, program yang disarankan
 * harus dari katalog, metrik acceptance rate dan konversi menjadi permintaan training.
 */
class AiCorporateInsightTest extends TestCase
{
    use RefreshDatabase;

    private const EM = "\u{2014}";

    private Organization $org;

    private User $hr;

    private User $admin;

    private OrganizationUnit $gudang;

    private FakeChatModel $model;

    /** @var array<string, TrainingPackage> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();
        $this->useModel($this->reply());

        $scada = Competency::query()->create(['code' => 'SCADA', 'name' => 'Sistem SCADA']);
        $plc = Competency::query()->create(['code' => 'PLC', 'name' => 'Pemrograman PLC']);
        foreach ([['scada', 'SCADA Dasar', $scada, 70], ['plc', 'PLC Dasar', $plc, 60]] as [$slug, $title, $comp, $level]) {
            $this->p[$slug] = TrainingPackage::query()->create(['slug' => $slug, 'title' => $title, 'status' => 'published', 'duration_hours' => 16]);
            $this->p[$slug]->competencyLinks()->attach($comp->id, ['level' => $level]);
        }

        $orgs = app(OrganizationService::class);
        $this->org = $orgs->create(['legal_name' => 'PT Rahasia Sejahtera']);
        $produksi = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'PRD', 'name' => 'Produksi']);
        $this->gudang = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'GDG', 'name' => 'Gudang']);
        $this->hr = User::factory()->create(['name' => 'Hana HR']);
        $orgs->addCorporateUser($this->org, $this->hr, 'hr_admin');
        $gaps = app(CompetencyGapService::class);
        $gaps->setTarget($this->org, 'Teknisi', $scada, 80, $this->hr);
        $gaps->setTarget($this->org, 'Teknisi', $plc, 60, $this->hr);
        $gaps->setTarget($this->org, 'Operator', $scada, 50, $this->hr);

        // 4 Teknisi di Produksi, 1 Operator di Gudang (kelompok kecil: tidak boleh dirinci).
        $i = 0;
        foreach (['Budi Teknisi', 'Ani Teknisi', 'Dedi Teknisi', 'Eka Teknisi'] as $name) {
            // Penulisan posisi berbeda (huruf kecil, spasi) tetap satu kelompok, seperti di matriks kompetensi.
            $m = $this->employ($name, $name === 'Eka Teknisi' ? ' teknisi' : 'Teknisi', $produksi, 'ABC-'.(++$i));
            if ($name === 'Ani Teknisi') {
                $this->took($m, 'scada', 'completed');   // level 70 < target 80
            }
            if ($name === 'Dedi Teknisi') {
                $this->took($m, 'plc', 'completed');     // level 60 = target, memenuhi
            }
        }
        $this->employ('Joko Operator', 'Operator', $this->gudang, 'ABC-9');

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function useModel(FakeChatModel $model): void
    {
        $this->model = $model;
        $this->app->instance(ChatModel::class, $model);
    }

    private function reply(array $override = []): FakeChatModel
    {
        return new FakeChatModel(json_encode($override + [
            'summary' => 'Cakupan SCADA untuk Teknisi masih rendah '.self::EM.' baru sebagian kecil yang punya bukti.',
            'key_gaps' => [
                ['competency' => 'Sistem SCADA', 'finding' => 'Rata-rata kurang 70 poin pada Teknisi.'],
                ['competency' => 'Kompetensi Karangan', 'finding' => 'Tidak ada di data.'],
            ],
            'actions' => [
                ['action' => 'Kirim Teknisi ke program SCADA', 'reason' => 'Gap terbesar', 'priority' => 'tinggi', 'program' => 'SCADA Dasar'],
                ['action' => 'Ikut program lain', 'reason' => 'Contoh', 'priority' => 'mendesak', 'program' => 'Program Karangan'],
            ],
            'limitations' => 'Banyak karyawan belum punya bukti penilaian.',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.api_key' => 'sk-test', 'ai.use_cases.corporate_insight.enabled' => true]);
    }

    private function employ(string $name, string $position, OrganizationUnit $unit, string $employeeId): Member
    {
        $u = User::factory()->create(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@example.com']);
        $m = app(MemberService::class)->createForUser($u);
        app(EmploymentService::class)->start($m, $this->org, ['position' => $position, 'employee_id' => $employeeId, 'unit_id' => $unit->id]);

        return $m;
    }

    private function took(Member $m, string $slug, string $status): Enrollment
    {
        $s = TrainingSchedule::query()->create(['package_id' => $this->p[$slug]->id, 'code' => strtoupper($slug).'-'.uniqid(), 'start_date' => '2026-10-01',
            'end_date' => '2026-10-03', 'status' => 'completed', 'quota' => 20]);

        return Enrollment::query()->create(['schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => 'Peserta', 'status' => $status,
            'type' => Enrollment::TYPE_CORPORATE, 'sponsor_organization_id' => $this->org->id, 'enrolled_at' => '2026-10-01', 'completed_at' => '2026-10-03']);
    }

    private function insightApi(User $user)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/insight");
    }

    public function test_off_by_default_nothing_is_generated_or_shown(): void
    {
        $this->assertNull(app(AiCorporateInsightService::class)->generate($this->org));
        $this->artisan('ai:corporate-insight')->expectsOutputToContain('belum tersedia')->assertSuccessful();
        $this->insightApi($this->hr)->assertOk()->assertJsonPath('data.status.available', false)->assertJsonPath('data.insight', null);
        $this->assertCount(0, $this->model->calls);
        $this->assertSame(0, AiInsight::count());
    }

    public function test_only_aggregates_without_names_or_small_groups_are_sent(): void
    {
        $this->enable();
        $a = app(AiCorporateInsightService::class)->aggregate($this->org);
        $this->assertSame(5, $a['employees_with_targets']);
        // SCADA: 4 Teknisi + 1 Operator; PLC: 4 Teknisi. Operator (1 orang) dan unit Gudang (1 orang) tidak dirinci.
        $this->assertSame(['Sistem SCADA', 'Pemrograman PLC'], array_column($a['competencies'], 'competency'));
        $this->assertSame(['Teknisi', 'Teknisi'], array_column($a['positions'], 'position'));
        $this->assertSame(['PRD · Produksi'], array_column($a['units'], 'unit'));
        $this->assertSame(['completed' => 2, 'enrolled' => 2], ['completed' => $a['outcomes_12m']['completed'], 'enrolled' => $a['outcomes_12m']['enrolled']]);
        $this->assertSame(['scada'], array_column($a['programs']['Sistem SCADA'], 'slug'));

        app(AiCorporateInsightService::class)->generate($this->org);
        $call = $this->model->calls[0];
        $this->assertSame(AiPrompt::STYLE_RULES, $call['system'][0]['text']);
        $this->assertSame(AiCorporateInsightService::SYSTEM_PROMPT, $call['system'][1]['text']);
        $this->assertSame(AiCorporateInsightService::schema(), $call['options']['json_schema']);
        $this->assertSame('claude-opus-5-5', $call['options']['model']);
        $prompt = $call['messages'][0]['content'];
        $this->assertStringContainsString('Teknisi / Sistem SCADA: target 80, 4 karyawan, memenuhi 0', $prompt);
        $this->assertStringContainsString('"SCADA Dasar" (level 70)', $prompt);
        foreach (['Budi', 'Ani', 'Joko', 'Operator', 'Gudang', 'ABC-1', 'PT Rahasia', $this->org->code, '@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $prompt, "tidak boleh terkirim: $secret");
        }
    }

    public function test_draft_until_admin_approves_with_traceable_programs(): void
    {
        $this->enable();
        $svc = app(AiCorporateInsightService::class);
        $insight = $svc->generate($this->org);

        $this->assertSame(AiInsight::STATUS_DRAFT, $insight->status);
        $this->assertSame($this->org->id, $insight->organization_id);
        $this->assertTrue($insight->subject->is($this->org));
        $this->assertSame('2026-11-17.2', $insight->prompt_version);
        $o = $insight->output;
        $this->assertStringNotContainsString(self::EM, $o['summary']);
        $this->assertSame(['Sistem SCADA'], array_column($o['key_gaps'], 'competency')); // kompetensi karangan dibuang
        $this->assertSame(['slug' => 'scada', 'title' => 'SCADA Dasar'], $o['actions'][0]['program']);
        $this->assertNull($o['actions'][1]['program']);                                    // program karangan dibuang
        $this->assertSame('sedang', $o['actions'][1]['priority']);
        $this->assertSame([$this->org->getMorphClass()], array_column($insight->data_reference['items'], 'type'));
        $this->assertStringNotContainsString('Teknisi', json_encode($insight->data_reference));
        $this->assertSame($insight->id, AiUsageRecord::query()->sole()->ai_insight_id);

        // Draf belum tampil dan belum bisa diberi umpan balik oleh perusahaan.
        $this->insightApi($this->hr)->assertOk()->assertJsonPath('data.insight', null)->assertJsonPath('data.canView', true);
        $this->postJson("/api/v1/ai-insights/{$insight->id}/feedback", ['helpful' => true])->assertNotFound();

        // Data tidak berubah: tidak ada draf baru. --force: draf baru menggantikan draf lama.
        $this->assertNull($svc->generate($this->org));
        $this->assertCount(1, $this->model->calls);
        $second = $svc->generate($this->org, force: true);
        $this->assertSame(AiInsight::STATUS_EXPIRED, $insight->fresh()->status);

        app(AiInsightService::class)->approve($second, $this->admin);
        $d = $this->insightApi($this->hr)->assertOk()->json('data.insight');
        $this->assertSame($second->id, $d['insightId']);
        $this->assertSame('scada', $d['actions'][0]['program']['slug']);
        $this->assertNotNull($d['approvedAt']);
        $this->assertStringNotContainsString('Budi', json_encode($d));
        $this->postJson("/api/v1/ai-insights/{$second->id}/feedback", ['helpful' => true])->assertOk();
        $this->assertTrue($this->insightApi($this->hr)->json('data.insight.myFeedback'));

        // Data berubah setelah disetujui: draf baru dibuat, yang disetujui tetap tampil sampai draf baru disetujui.
        $this->employ('Fajar Teknisi', 'Teknisi', OrganizationUnit::query()->where('code', 'PRD')->firstOrFail(), 'ABC-5');
        $third = $svc->generate($this->org);
        $this->assertSame(AiInsight::STATUS_DRAFT, $third->status);
        $this->assertSame($second->id, $this->insightApi($this->hr)->json('data.insight.insightId'));
    }

    public function test_only_organization_level_users_of_that_organization_see_it(): void
    {
        $this->enable();
        $insight = app(AiCorporateInsightService::class)->generate($this->org);
        app(AiInsightService::class)->approve($insight, $this->admin);
        $orgs = app(OrganizationService::class);

        $supervisor = User::factory()->create();
        $orgs->addCorporateUser($this->org, $supervisor, 'supervisor', $this->gudang);   // dibatasi unit
        $finance = User::factory()->create();
        $orgs->addCorporateUser($this->org, $finance, 'finance');
        $viewer = User::factory()->create();
        $orgs->addCorporateUser($this->org, $viewer, 'viewer');
        $outsider = User::factory()->create();
        $orgs->addCorporateUser($orgs->create(['legal_name' => 'PT Lain']), $outsider, 'owner');

        $this->insightApi($supervisor)->assertOk()->assertJsonPath('data.canView', false)->assertJsonPath('data.insight', null);
        $this->postJson("/api/v1/ai-insights/{$insight->id}/feedback", ['helpful' => true])->assertNotFound();
        $this->insightApi($finance)->assertOk()->assertJsonPath('data.insight', null);
        $this->insightApi($viewer)->assertOk()->assertJsonPath('data.insight.insightId', $insight->id);
        $this->insightApi($outsider)->assertNotFound();
        $this->postJson("/api/v1/ai-insights/{$insight->id}/feedback", ['helpful' => true])->assertNotFound();

        // Fitur dimatikan: insight yang sudah disetujui pun tidak ditampilkan.
        config(['ai.use_cases.corporate_insight.enabled' => false]);
        $this->insightApi($this->hr)->assertOk()->assertJsonPath('data.insight', null)->assertJsonPath('data.status.label', 'Belum tersedia');
    }

    public function test_too_little_data_or_model_failure_falls_back_to_the_plain_gap_report(): void
    {
        $this->enable();
        $small = app(OrganizationService::class)->create(['legal_name' => 'PT Kecil']);
        app(CompetencyGapService::class)->setTarget($small, 'Teknisi', Competency::query()->where('code', 'SCADA')->firstOrFail(), 80, $this->hr);
        $this->assertNull(app(AiCorporateInsightService::class)->generate($small));
        $this->assertCount(0, $this->model->calls);

        $this->useModel(new FakeChatModel(fail: true));
        $this->assertNull(app(AiCorporateInsightService::class)->generate($this->org));
        $this->useModel(new FakeChatModel(refuse: true));
        $this->assertNull(app(AiCorporateInsightService::class)->generate($this->org));
        $this->assertSame(0, AiInsight::count());
        $this->assertSame(['error', 'refused'], AiUsageRecord::query()->orderBy('id')->pluck('status')->all());

        // Gap report tanpa narasi tetap jalan.
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/competencies")->assertOk()->assertJsonPath('data.employeesWithTargets', 5);
    }

    public function test_command_and_evaluation_report_acceptance_and_conversion(): void
    {
        $this->enable();
        Storage::fake('local');
        $writes = [];
        $official = ['enrollments', 'registrations', 'certificates', 'competency_assessments', 'competency_targets', 'training_requests', 'members', 'invoices', 'employment_relationships'];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b('.implode('|', $official).')\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->artisan('ai:corporate-insight', ['--org' => strtolower($this->org->code)])->expectsOutputToContain('menunggu persetujuan admin')->assertSuccessful();
        $this->artisan('ai:corporate-insight', ['--org' => 'ORG-9999'])->assertFailed();
        $this->assertSame([], $writes, 'insight perusahaan tidak boleh menulis data resmi');

        // Insight disetujui 1 Oktober, lalu perusahaan mengajukan program yang disarankan pada 10 Oktober.
        Carbon::setTestNow('2026-10-01 09:00:00');
        $a = app(AiCorporateInsightService::class)->generate($this->org, force: true);
        app(AiInsightService::class)->approve($a, $this->admin);
        DB::table('training_requests')->insert(['code' => 'REQ-202610-0001', 'organization_id' => $this->org->id, 'package_id' => $this->p['scada']->id,
            'seats' => 4, 'status' => 'submitted', 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00']);
        Carbon::setTestNow('2026-11-20 10:00:00');
        $b = app(AiCorporateInsightService::class)->generate($this->org, force: true);
        app(AiInsightService::class)->reject($b, $this->admin, 'Saran terlalu umum untuk perusahaan ini');

        // Organisasi pembanding: punya target, belum pernah menerima insight, mengajukan permintaan bulan ini.
        $other = app(OrganizationService::class)->create(['legal_name' => 'PT Pembanding']);
        app(CompetencyGapService::class)->setTarget($other, 'Teknisi', Competency::query()->where('code', 'PLC')->firstOrFail(), 60, $this->hr);
        DB::table('training_requests')->insert(['code' => 'REQ-202611-0002', 'organization_id' => $other->id, 'package_id' => $this->p['plc']->id,
            'seats' => 2, 'status' => 'submitted', 'created_at' => '2026-11-15 10:00:00', 'updated_at' => '2026-11-15 10:00:00']);

        $m = app(AiCorporateInsightService::class)->evaluate();
        $this->assertSame(1, $m['approved']);
        $this->assertSame(1, $m['rejected']);
        $this->assertEquals(50.0, $m['acceptance_rate']);
        $this->assertSame(1, $m['mature']);
        $this->assertEquals(100.0, $m['conversion_rate']);
        $this->assertEquals(100.0, $m['suggested_program_conversion_rate']);
        $this->assertSame(1, $m['baseline_organizations']);
        $this->assertEquals(100.0, $m['baseline_conversion_rate']);
        $this->artisan('ai:evaluate', ['usecase' => 'corporate_insight'])->assertSuccessful();
    }
}
