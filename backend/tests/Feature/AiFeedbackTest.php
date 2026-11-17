<?php

namespace Tests\Feature;

use App\Filament\Resources\SurveyResponses\Pages\ListSurveyResponses;
use App\Models\AiBatch;
use App\Models\AiInsight;
use App\Models\AiUsageRecord;
use App\Models\Enrollment;
use App\Models\FeedbackLabel;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\SurveyResponse;
use App\Models\Trainer;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiFeedbackService;
use App\Services\AiInsightService;
use App\Services\MemberService;
use App\Services\SurveyService;
use App\Support\Ai\BatchModel;
use App\Support\Chatbot\ChatModel;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fakes\FakeBatchModel;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

/**
 * Survei evaluasi + analisis umpan balik: survei berjalan tanpa AI; klasifikasi Claude lewat Message Batches (palsu)
 * dengan penyaringan data pribadi, batas harian, ringkasan kelas yang wajib disetujui admin, dan evaluasi terhadap
 * label manual. Tidak ada panggilan API sungguhan.
 */
class AiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const EM = "\u{2014}";

    private FakeBatchModel $batch;

    private FakeChatModel $chat;

    private TrainingSchedule $schedule;

    private User $trainerUser;

    private User $admin;

    /** @var array<string, Enrollment> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->batch = new FakeBatchModel(function (array $r) {
            $text = $r['messages'][0]['content'];

            return ['text' => json_encode(str_contains($text, 'panas')
                ? ['sentiment' => 'negatif', 'category' => 'fasilitas', 'theme' => 'ruang kelas panas'.self::EM.'AC rusak', 'actionable' => true]
                : ['sentiment' => 'positif', 'category' => 'trainer', 'theme' => 'penjelasan jelas', 'actionable' => false])];
        });
        $this->app->instance(BatchModel::class, $this->batch);
        $this->chat = new FakeChatModel(json_encode(['summary' => 'Peserta puas'.self::EM.'namun ruang kelas panas.', 'strengths' => ['Trainer jelas'], 'improvements' => ['Perbaiki AC'], 'actions' => ['Servis AC sebelum kelas berikut']]));
        $this->app->instance(ChatModel::class, $this->chat);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);

        $p = TrainingPackage::query()->create(['slug' => 'plc-dasar', 'title' => 'PLC Dasar', 'status' => 'published', 'duration_hours' => 16]);
        $this->schedule = TrainingSchedule::query()->create(['package_id' => $p->id, 'code' => 'PLC-2611', 'start_date' => '2026-11-12', 'end_date' => '2026-11-15', 'status' => 'completed', 'quota' => 20]);
        $this->trainerUser = User::factory()->create(['name' => 'Andi Saputra']);
        $t = Trainer::query()->create(['name' => 'Andi Saputra', 'slug' => 'andi', 'user_id' => $this->trainerUser->id, 'email' => 'andi@example.com', 'is_active' => true]);
        $this->schedule->trainers()->attach($t->id, ['role' => 'lead']);
        foreach (['Budi Santoso' => 'completed', 'Cici Lestari' => 'completed', 'Dedi Kurnia' => 'failed'] as $name => $status) {
            $u = User::factory()->create(['name' => $name, 'email' => strtolower(strtok($name, ' ')).'@example.com']);
            $m = app(MemberService::class)->createForUser($u);
            $this->e[strtok($name, ' ')] = Enrollment::query()->create(['schedule_id' => $this->schedule->id, 'member_id' => $m->id, 'participant_name' => $name, 'status' => $status,
                'type' => Enrollment::TYPE_SELF, 'enrolled_at' => '2026-11-12', 'completed_at' => '2026-11-15']);
        }
        // Kelas yang belum selesai tidak diundang.
        $running = TrainingSchedule::query()->create(['package_id' => $p->id, 'code' => 'PLC-2612', 'start_date' => '2026-11-19', 'end_date' => '2026-11-25', 'status' => 'confirmed', 'quota' => 20]);
        Enrollment::query()->create(['schedule_id' => $running->id, 'member_id' => Member::query()->first()->id, 'participant_name' => 'Budi Santoso', 'status' => 'attending', 'type' => Enrollment::TYPE_SELF]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.use_cases.feedback_analysis.enabled' => true, 'ai.api_key' => 'sk-test']);
    }

    private function fill(string $who, array $answers): void
    {
        app(SurveyService::class)->submit(SurveyResponse::query()->where('enrollment_id', $this->e[$who]->id)->sole(), $answers);
    }

    private function inviteAndFill(): void
    {
        app(SurveyService::class)->inviteDue();
        $scale = ['overall' => 5, 'material' => 4, 'trainer' => 5, 'practice' => 3, 'facility' => 2];
        $this->fill('Budi', $scale + ['liked' => 'Pak Andi menjelaskan dengan jelas. Hubungi saya di budi@example.com atau 0812-3456-7890', 'improve' => 'Ruang kelas panas sekali']);
        $this->fill('Cici', $scale + ['liked' => 'Materinya bermanfaat untuk pekerjaan saya']);
        $this->fill('Dedi', $scale + ['improve' => 'AC mati, ruangan panas']);
    }

    public function test_survey_invitations_filling_and_reports_work_without_ai(): void
    {
        $this->assertSame(3, app(SurveyService::class)->inviteDue());
        $this->assertSame(0, app(SurveyService::class)->inviteDue(), 'aman diulang');
        $this->assertSame(3, NotificationDelivery::query()->where('audience', 'participant')->where('channel', 'database')->count());

        $budi = $this->e['Budi']->member->user;
        Sanctum::actingAs($budi);
        $list = $this->getJson('/api/v1/me/surveys')->assertOk()->json('data');
        $this->assertSame([['PLC-2611', 'pending']], array_map(fn ($r) => [$r['class']['code'], $r['status']], $list));
        $id = $list[0]['id'];
        $this->getJson("/api/v1/me/surveys/$id")->assertOk()->assertJsonCount(7, 'data.questions');
        $this->postJson("/api/v1/me/surveys/$id", ['answers' => ['overall' => 9]])->assertStatus(422)->assertJsonValidationErrors(['answers.overall', 'answers.material']);
        $this->postJson("/api/v1/me/surveys/$id", ['answers' => ['overall' => 5, 'material' => 4, 'trainer' => 5, 'practice' => 3, 'facility' => 2, 'improve' => 'Ruang panas']])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/v1/me/surveys/$id", ['answers' => ['overall' => 1]])->assertStatus(422);
        $other = SurveyResponse::query()->where('enrollment_id', $this->e['Cici']->id)->value('id');
        $this->getJson("/api/v1/me/surveys/$other")->assertNotFound();

        // AI mati: tidak ada batch, laporan trainer tetap jalan.
        $this->artisan('ai:feedback-analyze')->expectsOutputToContain('belum tersedia')->assertSuccessful();
        $this->assertSame([], $this->batch->batches);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->trainerUser);
        $d = $this->getJson('/api/v1/trainer/classes/PLC-2611/survey')->assertOk()->json('data');
        $this->assertSame([3, 1], [$d['report']['invited'], $d['report']['submitted']]);
        // Baru 1 pengisi: rincian disembunyikan dari trainer agar jawaban tidak bisa ditebak.
        $this->assertTrue($d['report']['detailHidden']);
        $this->assertSame([], $d['report']['comments']);
        $this->assertNull($d['report']['questions'][0]['average']);
        $this->assertNull($d['summary']);

        // Setelah 3 pengisi: skor dan komentar tampil tanpa nama dan tanpa ID respons.
        foreach (['Cici', 'Dedi'] as $who) {
            $this->fill($who, ['overall' => 4, 'material' => 4, 'trainer' => 4, 'practice' => 4, 'facility' => 4]);
        }
        $d = $this->getJson('/api/v1/trainer/classes/PLC-2611/survey')->assertOk()->json('data');
        $this->assertFalse($d['report']['detailHidden']);
        $this->assertSame('Ruang panas', $d['report']['comments'][0]['text']);
        $this->assertArrayNotHasKey('responseId', $d['report']['comments'][0]);
        $this->assertStringNotContainsString('Budi', json_encode($d));
    }

    public function test_comments_are_scrubbed_and_classified_through_batches(): void
    {
        $this->inviteAndFill();
        $this->enable();
        $r = app(AiFeedbackService::class)->analyze();
        $this->assertSame(4, $r['submitted']);
        $sent = collect(array_values($this->batch->batches)[0]);
        $budi = $sent->first(fn ($x) => str_contains($x['messages'][0]['content'], 'menjelaskan'))['messages'][0]['content'];
        $this->assertSame('Komentar: Pak [nama] menjelaskan dengan jelas. Hubungi saya di [email] atau [telepon]', $budi);
        foreach ($sent as $x) {
            $this->assertStringNotContainsString('Budi', $x['messages'][0]['content']);
            $this->assertStringContainsString('em dash', $x['system'][0]['text'], 'aturan gaya selalu blok pertama');
            $this->assertSame(['sentiment', 'category', 'theme', 'actionable'], $x['options']['json_schema']['required']);
        }
        $this->assertSame(4, AiBatch::query()->sole()->request_count);
        $this->assertSame(0, AiInsight::query()->count(), 'hasil baru dibaca pada putaran berikutnya');

        $r2 = app(AiFeedbackService::class)->analyze();
        $this->assertSame([1, 4, 0], [$r2['ended'], $r2['classified'], $r2['submitted']]);
        $cls = app(AiFeedbackService::class)->classifications();
        $this->assertCount(4, $cls);
        $hot = $cls->firstWhere('category', 'fasilitas');
        $this->assertSame('negatif', $hot['sentiment']);
        $this->assertSame('ruang kelas panas - AC rusak', $hot['theme'], 'teks AI dinormalkan');
        $this->assertSame(AiInsight::STATUS_APPROVED, AiInsight::query()->first()->status, 'klasifikasi internal hanya untuk staf');
        $usage = AiUsageRecord::query()->where('use_case', 'feedback_analysis')->get();
        $this->assertCount(4, $usage);
        // Harga batch setengah: (200 x 4 + 30 x 20 + 1500 x 0,2) / 1 juta x 0,5
        $this->assertEqualsWithDelta(0.00085, $usage->first()->cost_usd, 0.000001);
        $this->assertSame(0, app(AiFeedbackService::class)->analyze()['submitted'], 'komentar yang sudah diklasifikasi tidak dikirim ulang');
    }

    public function test_daily_limit_and_refusals_fall_back(): void
    {
        $this->inviteAndFill();
        $this->enable();
        config(['ai.use_cases.feedback_analysis.daily_calls' => 2]);
        $this->assertSame(2, app(AiFeedbackService::class)->analyze()['submitted']);
        $this->assertSame(0, app(AiFeedbackService::class)->analyze()['submitted'], 'batch hari ini ikut dihitung ke batas');
        $this->assertGreaterThanOrEqual(1, AiUsageRecord::query()->where('status', 'limited')->count());

        $this->batch->respond = fn () => ['text' => '', 'refused' => true];
        Carbon::setTestNow('2026-11-21 10:00:00');
        app(AiFeedbackService::class)->analyze();   // kirim 2 komentar sisa
        app(AiFeedbackService::class)->analyze();   // baca hasil: ditolak model
        $this->assertSame(2, AiUsageRecord::query()->where('status', 'refused')->count());
        // 2 komentar pertama terklasifikasi kemarin; 2 yang ditolak model tetap tanpa klasifikasi (dibaca manual) dan tidak dikirim ulang terus-menerus.
        $this->assertCount(2, app(AiFeedbackService::class)->classifications());
        $this->assertCount(0, app(AiFeedbackService::class)->pending());
    }

    public function test_class_summary_needs_admin_approval_before_trainer_sees_it(): void
    {
        $this->inviteAndFill();
        $this->enable();
        app(AiFeedbackService::class)->analyze();
        app(AiFeedbackService::class)->analyze();
        $this->artisan('ai:feedback-summarize')->expectsOutputToContain('Ringkasan kelas baru: 1')->assertSuccessful();
        $summary = AiInsight::query()->where('subject_type', (new TrainingSchedule)->getMorphClass())->sole();
        $this->assertSame(AiInsight::STATUS_DRAFT, $summary->status);
        $this->assertSame('Peserta puas - namun ruang kelas panas.', $summary->output['summary']);
        $prompt = $this->chat->calls[0]['messages'][0]['content'];
        $this->assertStringNotContainsString('Budi', $prompt);
        $this->assertStringNotContainsString('Andi', $prompt);
        $this->assertSame(0, app(AiFeedbackService::class)->summarizeDue(), 'tidak membuat ulang tanpa klasifikasi baru');

        Sanctum::actingAs($this->trainerUser);
        $this->assertNull($this->getJson('/api/v1/trainer/classes/PLC-2611/survey')->json('data.summary'));
        app(AiInsightService::class)->approve($summary, $this->admin);
        $this->getJson('/api/v1/trainer/classes/PLC-2611/survey')->assertJsonPath('data.summary.summary', 'Peserta puas - namun ruang kelas panas.')
            ->assertJsonPath('data.summary.aiGenerated', true);
        $this->postJson("/api/v1/ai-insights/{$summary->id}/feedback", ['helpful' => true])->assertOk();

        $this->app['auth']->forgetGuards();
        $other = User::factory()->create();
        Trainer::query()->create(['name' => 'Lain', 'slug' => 'lain', 'user_id' => $other->id, 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/ai-insights/{$summary->id}/feedback", ['helpful' => true])->assertNotFound();
    }

    public function test_evaluation_against_manual_labels(): void
    {
        $this->inviteAndFill();
        $this->enable();
        app(AiFeedbackService::class)->analyze();
        app(AiFeedbackService::class)->analyze();
        foreach (SurveyResponse::query()->where('status', 'submitted')->get() as $r) {
            foreach ($r->comments() as $key => $text) {
                $hot = str_contains($text, 'panas');
                FeedbackLabel::query()->create(['response_id' => $r->id, 'question_key' => $key, 'sentiment' => $hot ? 'negatif' : 'positif',
                    'category' => $hot ? 'fasilitas' : (str_contains($text, 'Materi') ? 'materi' : 'trainer'), 'actionable' => $hot, 'labeled_by' => $this->admin->id]);
            }
        }
        $ev = app(AiFeedbackService::class)->evaluate();
        $this->assertSame([4, 4], [$ev['labeled'], $ev['compared']]);
        $this->assertSame(1.0, $ev['sentiment']['accuracy']);
        $this->assertSame(0.75, $ev['category']['accuracy'], 'komentar materi diklasifikasi AI palsu sebagai trainer');
        $this->assertSame(1.0, $ev['actionable']['f1']);
        $this->assertNotNull($ev['baseline_sentiment']['accuracy']);

        $this->artisan('ai:evaluate', ['usecase' => 'feedback_analysis'])
            ->expectsOutputToContain('sentiment_accuracy')
            ->expectsOutputToContain('Data belum cukup')
            ->assertSuccessful();
    }

    public function test_ai_path_never_writes_survey_or_official_tables(): void
    {
        $this->inviteAndFill();
        $this->enable();
        $official = ['survey_responses', 'feedback_labels', 'enrollments', 'registrations', 'certificates', 'competency_assessments', 'members'];
        $writes = [];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b(into|from|update)?\s*"?('.implode('|', $official).')"?\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        app(AiFeedbackService::class)->analyze();
        app(AiFeedbackService::class)->analyze();
        app(AiFeedbackService::class)->summarizeDue();
        app(AiFeedbackService::class)->evaluate();
        $this->assertSame([], $writes);
    }

    public function test_admin_pages_and_manual_label_action(): void
    {
        $this->inviteAndFill();
        $this->enable();
        app(AiFeedbackService::class)->analyze();
        app(AiFeedbackService::class)->analyze();
        $this->actingAs($this->admin);
        $this->get('/admin/survei-evaluasi')->assertOk()->assertSee('Survei evaluasi')->assertSee('Ruang kelas panas sekali')
            ->assertSee('Dibuat AI: Negatif')->assertSee('Rata-rata per program dan trainer');
        $this->get('/admin/survey-templates')->assertOk()->assertSee('Survei evaluasi pelatihan (bawaan)');
        $r = SurveyResponse::query()->where('enrollment_id', $this->e['Dedi']->id)->sole();
        Livewire::test(ListSurveyResponses::class)
            ->callTableAction('label', $r, ['question_key' => 'improve', 'sentiment' => 'negatif', 'category' => 'fasilitas', 'theme' => 'AC', 'actionable' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame('fasilitas', FeedbackLabel::query()->sole()->category);
    }
}
