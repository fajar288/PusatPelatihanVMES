<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiUsageRecord;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiGovernance;
use App\Services\AiInsightService;
use App\Services\AiModelRunner;
use App\Services\AiReadiness;
use App\Support\Ai\AiPrompt;
use App\Support\Ai\AiUseCases;
use App\Support\Ai\DataReference;
use App\Support\Ai\PersonalData;
use App\Support\Chatbot\ChatModel;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

/**
 * Fondasi tata kelola AI terukur: mati bawaan per use case, gerbang panggilan Claude (flag, API key, jeda, batas harian),
 * pengaman data pribadi, normalisasi teks, pencatatan pemakaian + biaya, siklus insight dengan tinjauan manusia,
 * umpan balik, perintah ai:readiness / ai:evaluate, dan bukti tidak ada jalur dari AI ke tabel data resmi.
 */
class AiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const EM = "\u{2014}";

    private const EN = "\u{2013}";

    private FakeChatModel $model;

    private User $admin;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        $this->model = new FakeChatModel;
        $this->app->instance(ChatModel::class, $this->model);
        Http::preventStrayRequests();
        Cache::flush();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->coordinator = User::factory()->create(['role' => 'training_coordinator']);
        $this->seed(RolesSeeder::class); // sinkronkan user_roles dari kolom role
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(string $useCase = 'feedback_analysis', bool $key = true): void
    {
        config(['ai.enabled' => true, "ai.use_cases.$useCase.enabled" => true, 'ai.api_key' => $key ? 'sk-test' : null]);
    }

    private function callAi(string $text = 'Ringkas komentar ini', ?array $schema = null)
    {
        return app(AiModelRunner::class)->run('feedback_analysis', [['type' => 'text', 'text' => 'Klasifikasikan komentar.', 'cache' => true]], [['role' => 'user', 'content' => $text]], $schema);
    }

    public function test_all_use_cases_are_off_by_default_and_runner_falls_back_without_calling_the_model(): void
    {
        $gov = app(AiGovernance::class);
        $this->assertFalse(config('ai.enabled'));
        foreach (AiUseCases::keys() as $key) {
            $uc = AiUseCases::get($key);
            $this->assertFalse($uc['enabled'], "$key harus mati bawaan");
            $this->assertFalse($gov->available($key), "$key harus tidak tersedia");
        }
        $this->assertSame('global_off', $gov->status('early_warning')['reason']);
        // Chatbot publik tidak ikut flag global: alasannya flag sendiri.
        $this->assertSame('flag_off', $gov->status('public_chatbot')['reason']);
        $this->assertSame('claude-opus-5-5', AiUseCases::get('feedback_analysis')['model']);

        $result = $this->callAi();
        $this->assertFalse($result->ok());
        $this->assertSame('unavailable', $result->status);
        $this->assertSame('global_off', $result->reason);
        $this->assertCount(0, $this->model->calls);
        $this->assertSame(0, AiUsageRecord::query()->count(), 'fitur yang belum dinyalakan tidak perlu dicatat');

        config(['ai.enabled' => true]);
        $this->assertSame('flag_off', $gov->status('feedback_analysis')['reason']);
        $this->enable(key: false);
        $this->assertSame('no_api_key', $gov->status('feedback_analysis')['reason']);
        // Use case statistik tidak butuh API key.
        $this->enable('early_warning', key: false);
        $this->assertTrue($gov->available('early_warning'));
        $this->enable();
        $this->assertTrue($gov->available('feedback_analysis'));
    }

    public function test_runner_uses_use_case_settings_style_rules_records_usage_and_normalizes_text(): void
    {
        $this->enable();
        config(['ai.use_cases.feedback_analysis.effort' => 'low', 'ai.use_cases.feedback_analysis.max_tokens' => 900, 'ai.timeout' => 12]);
        $this->model->text = 'Tema: fasilitas'.self::EM.'ruang kelas panas, jam 08.00 '.self::EN.' 17.00';

        $result = $this->callAi();

        $this->assertTrue($result->ok());
        $this->assertSame('Tema: fasilitas - ruang kelas panas, jam 08.00-17.00', $result->text);
        $call = $this->model->calls[0];
        $this->assertSame(['model' => 'claude-opus-5-5', 'effort' => 'low', 'max_tokens' => 900, 'timeout' => 12.0], $call['options']);
        // Aturan gaya selalu blok pertama; berisi larangan em/en dash dan rujukan dokumen internal, tanpa karakter dash-nya.
        $this->assertSame(AiPrompt::STYLE_RULES, $call['system'][0]['text']);
        $this->assertStringContainsString('em dash', $call['system'][0]['text']);
        $this->assertStringContainsString('dokumen internal', $call['system'][0]['text']);
        $this->assertStringNotContainsString(self::EM, $call['system'][0]['text']);
        $this->assertStringNotContainsString(self::EN, $call['system'][0]['text']);
        $this->assertTrue($call['system'][1]['cache']);

        $usage = AiUsageRecord::query()->sole();
        $this->assertSame('ok', $usage->status);
        $this->assertSame([120, 40, 3000], [$usage->input_tokens, $usage->output_tokens, $usage->cache_read_tokens]);
        // 120 x $4 + 40 x $20 + 3000 x $0,20 per 1 juta token
        $this->assertEqualsWithDelta(0.00188, $usage->cost_usd, 0.000001);
        $this->assertSame(AiUseCases::get('feedback_analysis')['prompt_version'], $usage->prompt_version);
        $this->assertSame(1, app(AiGovernance::class)->usageToday('feedback_analysis')['calls']);
    }

    public function test_structured_output_is_decoded_and_normalized(): void
    {
        $this->enable();
        $schema = ['type' => 'object', 'properties' => ['theme' => ['type' => 'string']], 'required' => ['theme'], 'additionalProperties' => false];
        $this->model->text = json_encode(['theme' => 'Materi '.self::EM.' terlalu cepat', 'tags' => ['tempo'.self::EN.'praktik']]);

        $result = $this->callAi(schema: $schema);

        $this->assertTrue($result->ok());
        $this->assertSame(['theme' => 'Materi - terlalu cepat', 'tags' => ['tempo-praktik']], $result->data);
        $this->assertSame($schema, $this->model->calls[0]['options']['json_schema']);

        $this->model->text = 'bukan json';
        $bad = $this->callAi(schema: $schema);
        $this->assertSame('error', $bad->status);
        $this->assertSame('invalid_json', $bad->reason);
    }

    public function test_refusal_and_errors_fall_back_and_are_recorded(): void
    {
        $this->enable();
        $this->model->refuse = true;
        $refused = $this->callAi();
        $this->assertSame('refused', $refused->status);
        $this->assertNull($refused->text);

        $this->model->refuse = false;
        $this->model->fail = true;
        $failed = $this->callAi();
        $this->assertSame('error', $failed->status);

        $this->assertSame(['refused', 'error'], AiUsageRecord::query()->orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('overloaded', (string) AiUsageRecord::query()->where('status', 'error')->value('error'));
    }

    public function test_personal_data_is_never_sent_to_the_model(): void
    {
        $this->enable();
        foreach (['Hubungi saya di budi@example.com', 'WA 0812-3456-7890 ya', 'NIK 3171 0123 4567 8901'] as $text) {
            $result = $this->callAi($text);
            $this->assertSame('blocked', $result->status, $text);
            $this->assertSame('personal_data', $result->reason);
        }
        $this->assertCount(0, $this->model->calls);
        $this->assertSame(3, AiUsageRecord::query()->where('status', 'error')->count());

        // Setelah disaring, teks yang sama boleh dikirim.
        $this->assertTrue($this->callAi(PersonalData::scrub('Hubungi saya di budi@example.com atau +62 812 3456 7890'))->ok());
        $this->assertSame('Hubungi saya di [email] atau [telepon]', $this->model->calls[0]['messages'][0]['content']);
    }

    public function test_daily_call_and_token_limits_fall_back_and_log_the_event(): void
    {
        $this->enable();
        config(['ai.use_cases.feedback_analysis.daily_calls' => 2]);
        $this->assertTrue($this->callAi()->ok());
        $this->assertTrue($this->callAi()->ok());
        $limited = $this->callAi();
        $this->assertSame('unavailable', $limited->status);
        $this->assertSame('limit_calls', $limited->reason);
        $this->assertCount(2, $this->model->calls);
        $this->assertSame(1, AiUsageRecord::query()->where('status', 'limited')->count());

        // Hari berikutnya kuota kembali.
        Carbon::setTestNow('2026-11-18 08:00:00');
        $this->assertTrue($this->callAi()->ok());

        // Batas token: satu panggilan fake = 3160 token.
        config(['ai.use_cases.feedback_analysis.daily_calls' => 0, 'ai.use_cases.feedback_analysis.daily_tokens' => 5000]);
        $this->assertTrue($this->callAi()->ok());
        $this->assertSame('limit_tokens', $this->callAi()->reason);
    }

    public function test_admin_pause_stops_the_use_case_and_is_audited(): void
    {
        $this->enable();
        $gov = app(AiGovernance::class);
        $gov->pause('feedback_analysis', $this->admin, 'Biaya naik');

        $result = $this->callAi();
        $this->assertSame('paused', $result->reason);
        $this->assertCount(0, $this->model->calls);
        $this->assertSame(1, AiUsageRecord::query()->where('status', 'paused')->count());
        $log = AuditLog::query()->where('action', 'ai.use_case_paused')->sole();
        $this->assertSame('feedback_analysis', $log->after['use_case']);
        $this->assertSame($this->admin->id, $log->actor_id);

        $gov->resume('feedback_analysis', $this->admin);
        $this->assertTrue($this->callAi()->ok());
        $this->assertSame(1, AuditLog::query()->where('action', 'ai.use_case_resumed')->count());

        // Jeda tidak bisa menyalakan fitur yang flag env-nya mati.
        $gov->resume('early_warning', $this->admin);
        $this->assertFalse($gov->available('early_warning'));
    }

    public function test_insights_follow_review_rules_and_store_only_references(): void
    {
        $svc = app(AiInsightService::class);
        $schedule = TrainingSchedule::query()->firstOrFail();
        $ref = (new DataReference)->add($schedule, ['attendance' => 0.5, 'progress' => 0.2]);
        $this->enable();
        $usage = $this->callAi()->usage;

        $draft = $svc->record('feedback_analysis', ['summary' => 'Peserta puas '.self::EM.' tempo cepat'], $ref, $schedule, usage: $usage);
        $this->assertSame(AiInsight::STATUS_DRAFT, $draft->status, 'use case dengan tinjauan wajib mulai sebagai draft');
        $this->assertSame('Peserta puas - tempo cepat', $draft->output['summary']);
        $this->assertSame('claude-opus-5-5', $draft->model_version);
        $this->assertSame($draft->id, $usage->fresh()->ai_insight_id);
        $this->assertSame(TrainingSchedule::class, $draft->subject_type);
        // Referensi data hanya jenis, ID, dan hash.
        $item = $draft->data_reference['items'][0];
        $this->assertSame(['type', 'id', 'hash'], array_keys($item));
        $this->assertSame(64, strlen($item['hash']));
        $this->assertSame(DataReference::hash(['progress' => 0.2, 'attendance' => 0.5]), $item['hash'], 'urutan kunci tidak mengubah hash');
        $this->assertFalse(AiInsight::query()->visible()->whereKey($draft->id)->exists());

        $logged = $svc->record('recommendation', ['reason' => 'Anda lulus PLC Dasar'], new DataReference);
        $this->assertSame(AiInsight::STATUS_APPROVED, $logged->status, 'rekomendasi boleh langsung tampil, tetap dicatat');
        $this->assertNull($logged->reviewer_id);
        $this->assertSame('statistical', $logged->model_version);
    }

    public function test_review_requires_permission_reason_and_is_audited(): void
    {
        $svc = app(AiInsightService::class);
        $a = $svc->record('corporate_insight', ['summary' => 'Gap SCADA 18 poin'], new DataReference);
        $b = $svc->record('corporate_insight', ['summary' => 'Gap PLC 10 poin'], new DataReference);

        $marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $this->seed(RolesSeeder::class);
        try {
            $svc->approve($a, $marketing);
            $this->fail('pengguna tanpa ai.review tidak boleh meninjau');
        } catch (ValidationException) {
        }

        $approved = $svc->approve($a, $this->coordinator);
        $this->assertSame(AiInsight::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->coordinator->id, $approved->reviewer_id);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertTrue(AiInsight::query()->visible()->whereKey($a->id)->exists());
        $this->assertThrows(fn () => $svc->approve($a->fresh(), $this->admin), ValidationException::class);

        $this->assertThrows(fn () => $svc->reject($b, $this->admin, 'kurang'), ValidationException::class);
        $rejected = $svc->reject($b, $this->admin, 'Angka gap tidak sesuai data terbaru');
        $this->assertSame(AiInsight::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Angka gap tidak sesuai data terbaru', $rejected->rejection_reason);
        // Insight yang sudah tampil masih bisa ditarik.
        $this->assertSame(AiInsight::STATUS_REJECTED, $svc->reject($a->fresh(), $this->admin, 'Ternyata keliru setelah dicek')->status);

        $this->assertSame(['ai.insight_approved', 'ai.insight_rejected', 'ai.insight_rejected'], AuditLog::query()->where('action', 'like', 'ai.insight_%')->orderBy('id')->pluck('action')->all());
    }

    public function test_unreviewed_and_outdated_insights_expire(): void
    {
        $svc = app(AiInsightService::class);
        $old = $svc->record('corporate_insight', ['summary' => 'lama'], new DataReference);
        $fresh = $svc->record('corporate_insight', ['summary' => 'baru'], new DataReference);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $dated = $svc->record('recommendation', ['reason' => 'x'], new DataReference, expiresAt: now()->addDay());

        $this->assertSame(1, $svc->expireDue());
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, $svc->expireDue());

        $this->assertSame(AiInsight::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertSame(AiInsight::STATUS_DRAFT, $fresh->fresh()->status);
        $this->assertSame(AiInsight::STATUS_EXPIRED, $dated->fresh()->status);
        $this->artisan('ai:expire-insights')->assertSuccessful();
    }

    public function test_feedback_only_on_visible_insights_one_per_user(): void
    {
        $svc = app(AiInsightService::class);
        $visible = $svc->record('recommendation', ['reason' => 'Lanjut ke PLC Lanjutan'], new DataReference);
        $draft = $svc->record('corporate_insight', ['summary' => 'draf'], new DataReference);
        $member = User::factory()->create();

        $svc->feedback($visible, $member, true);
        $svc->feedback($visible, $member, false, 'Sudah pernah ikut');
        $this->assertSame(1, AiInsightFeedback::query()->count());
        $this->assertFalse(AiInsightFeedback::query()->sole()->helpful);
        $this->assertThrows(fn () => $svc->feedback($draft, $member, true), ValidationException::class);
    }

    public function test_ai_pipeline_never_writes_official_tables(): void
    {
        $official = ['enrollments', 'registrations', 'attendances', 'certificates', 'competency_assessments', 'competency_assessment_scores',
            'quiz_attempts', 'assignment_submissions', 'lesson_progress', 'invoices', 'invoice_items', 'payment_transactions', 'payment_refunds', 'members'];
        $writes = [];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b(into|from|update)?\s*"?('.implode('|', $official).')"?\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->enable();
        $svc = app(AiInsightService::class);
        $usage = $this->callAi()->usage;
        $insight = $svc->record('feedback_analysis', ['summary' => 'Peserta perlu praktik tambahan'], (new DataReference)->add(TrainingSchedule::query()->firstOrFail()), usage: $usage);
        $svc->approve($insight, $this->admin);
        $svc->feedback($insight, $this->admin, true);
        $svc->reject($insight->fresh(), $this->admin, 'Uji jalur penolakan');
        $svc->expireDue();
        app(AiGovernance::class)->pause('feedback_analysis', $this->admin);
        app(AiReadiness::class)->all();
        $this->artisan('ai:evaluate', ['usecase' => 'feedback_analysis'])->assertSuccessful();

        $this->assertSame([], $writes, 'layanan AI tidak boleh menulis ke tabel data resmi');

        // Secara statis: kode AI tidak memakai model atau service yang mengubah data resmi.
        $files = array_merge(
            glob(app_path('Services/Ai*.php')),
            glob(app_path('Support/Ai/*.php')),
            glob(app_path('Support/Ai/Evaluators/*.php')),
            glob(app_path('Filament/Resources/Ai*/*.php')),
            glob(app_path('Filament/Resources/Ai*/Pages/*.php')),
            [app_path('Filament/Pages/AiStatus.php')],
        );
        $this->assertGreaterThan(10, count($files));
        $forbidden = '/use App\\\\(Models\\\\(Enrollment|Registration|Attendance|Certificate|CompetencyAssessment|QuizAttempt|AssignmentSubmission|LessonProgress|Invoice|PaymentTransaction|PaymentRefund|Member)|Services\\\\(Completion|Certificate|Payment|Invoice|Refund|Enrollment|Member|Monitoring|Lms)Service);/';
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression($forbidden, (string) file_get_contents($file), basename($file));
        }
    }

    public function test_public_chatbot_usage_is_recorded_with_cost(): void
    {
        config(['chatbot.enabled' => true, 'chatbot.api_key' => 'sk-test']);
        $this->postJson('/api/v1/chat', ['conversationId' => 'conv_ai', 'messages' => [['role' => 'user', 'content' => 'Kapan jadwal CCNA?']]])->assertOk();

        $usage = AiUsageRecord::query()->sole();
        $this->assertSame('public_chatbot', $usage->use_case);
        $this->assertSame('ok', $usage->status);
        $this->assertSame(config('chatbot.prompt_version'), $usage->prompt_version);
        $this->assertGreaterThan(0, $usage->cost_usd);
        // Chatbot publik tetap dengan konfigurasinya sendiri (tanpa opsi tambahan).
        $this->assertSame([], $this->model->calls[0]['options']);
    }

    public function test_readiness_and_evaluate_commands(): void
    {
        Storage::fake('local');
        $report = app(AiReadiness::class)->report('lms_assistant');
        $before = $report['checks'][0]['actual'];
        $this->assertFalse($report['ready']);
        $this->assertSame(30, $report['checks'][0]['required']);
        Lesson::query()->where('is_published', true)->first()?->update(['body' => '<p>Materi</p>']);

        $this->artisan('ai:readiness')->expectsOutputToContain('Data belum cukup')->assertSuccessful();
        $this->artisan('ai:readiness', ['usecase' => 'tidak_ada'])->assertFailed();
        $this->artisan('ai:evaluate', ['usecase' => 'tidak_ada'])->assertFailed();

        $this->artisan('ai:evaluate', ['usecase' => 'public_chatbot'])
            ->expectsOutputToContain('Chatbot informasi publik')
            ->expectsOutputToContain('Laporan disimpan')
            ->assertSuccessful();
        $files = Storage::disk('local')->files('ai-evaluations');
        $this->assertCount(1, $files);
        $json = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame('public_chatbot', $json['use_case']);
        $this->assertArrayHasKey('answered_rate', $json['specific']['metrics']);
        $this->assertArrayHasKey('usage_30d', $json['governance']);

        $this->artisan('ai:evaluate', ['usecase' => 'early_warning'])
            ->expectsOutputToContain('Data belum cukup')
            ->assertSuccessful();
        $this->assertGreaterThanOrEqual($before, app(AiReadiness::class)->report('lms_assistant')['checks'][0]['actual']);
    }
}
