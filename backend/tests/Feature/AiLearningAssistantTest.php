<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AiInsight;
use App\Models\AiUsageRecord;
use App\Models\ChatLog;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiGovernance;
use App\Services\AiLearningAssistantService;
use App\Services\RegistrationService;
use App\Support\Ai\AiPrompt;
use App\Support\Ai\Evaluators\LmsAssistantEvaluator;
use App\Support\Chatbot\ChatModel;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

/**
 * Asisten Belajar LMS (use case lms_assistant): jawaban hanya dari materi lesson terbit pada course yang boleh diakses,
 * dengan sumber; batas per peserta; tanpa data pribadi ke model; metrik kualitas, cakupan sumber, unresolved rate.
 */
class AiLearningAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const EM = "\u{2014}";

    private const EN = "\u{2013}";

    private Course $course;

    private Lesson $osi;

    private Lesson $ip;

    private Lesson $draft;

    private Lesson $later;

    private Lesson $quiz;

    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00'); // kelas CCNA-2611 berlangsung 17-21 Nov 2026
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(config('filesystems.default'));
        Http::fake();
        $this->useModel(new FakeChatModel);

        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail();
        $this->course = Course::query()->create(['package_id' => $schedule->package_id, 'title' => 'CCNA Online Course', 'slug' => 'ccna-online', 'is_published' => true]);
        $m1 = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Dasar Jaringan', 'sort_order' => 1]);
        $m2 = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Pengalamatan', 'sort_order' => 2]);
        $this->osi = Lesson::query()->create(['module_id' => $m1->id, 'title' => 'Model OSI', 'type' => 'text', 'sort_order' => 1,
            'body' => '<h2>Model OSI</h2><p>Model OSI memiliki <b>7 lapisan</b>.</p><ul><li>Physical</li><li>Data link</li></ul><p>Kontak trainer: andi@vmes.id / 0812-3456-7890</p>']);
        $this->ip = Lesson::query()->create(['module_id' => $m2->id, 'title' => 'IPv4', 'type' => 'video', 'sort_order' => 1,
            'settings' => ['video_url' => 'https://www.youtube.com/watch?v=abcdefghijk'], 'body' => '<p>Alamat IPv4 terdiri dari 32 bit &amp; ditulis dalam 4 oktet.</p>']);
        $this->draft = Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Draf IPv6', 'type' => 'text', 'sort_order' => 2, 'is_published' => false, 'body' => '<p>RAHASIA belum terbit</p>']);
        $this->later = Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Subnetting lanjut', 'type' => 'text', 'sort_order' => 3, 'available_from' => '2026-11-20', 'body' => '<p>Materi BESOK</p>']);
        $this->quiz = Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Post-test', 'type' => 'quiz', 'sort_order' => 4, 'body' => '<p>Kunci: jawaban B</p>', 'settings' => ['kind' => 'post_test']]);
        Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Tugas', 'type' => 'assignment', 'sort_order' => 5, 'body' => '<p>Kerjakan subnet KUNCI TUGAS</p>']);
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

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.api_key' => 'sk-test', 'ai.use_cases.lms_assistant.enabled' => true]);
    }

    /** @return array{0: User, 1: Enrollment} */
    private function enrolledMember(string $email = 'peserta@example.com', string $name = 'Budi Santoso'): array
    {
        $member = User::factory()->create(['email' => $email, 'name' => $name]);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $name, 'email' => $email, 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $admin);
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $admin);
        $this->app['auth']->forgetGuards();

        return [$member, Enrollment::query()->where('registration_id', $reg->id)->firstOrFail()];
    }

    /** Jawaban bersitasi: dokumen 0 = Model OSI, dokumen 1 = IPv4 (urutan course). */
    private function citedModel(): FakeChatModel
    {
        return new FakeChatModel(segments: [
            ['text' => 'Model OSI terdiri dari 7 lapisan', 'citations' => [['document_index' => 0, 'document_title' => 'Dasar Jaringan: Model OSI', 'cited_text' => 'Model OSI memiliki 7 lapisan.']]],
            ['text' => ' '.self::EM.' sedangkan alamat IPv4 panjangnya 32 bit (lapisan 1'.self::EN.'7 di OSI).', 'citations' => [['document_index' => 1, 'document_title' => 'Pengalamatan: IPv4', 'cited_text' => 'Alamat IPv4 terdiri dari 32 bit']]],
            ['text' => ' Detailnya ada di '.self::EM.' modul 1.', 'citations' => [['document_index' => 0, 'document_title' => 'Dasar Jaringan: Model OSI', 'cited_text' => 'Model OSI memiliki 7 lapisan.']]],
        ]);
    }

    private function ask(User $user, array $body = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/assistant/messages', $body + ['course' => 'ccna-online', 'question' => 'Berapa lapisan model OSI?']);
    }

    public function test_off_by_default_status_says_not_available_and_nothing_is_sent_or_stored(): void
    {
        [$member] = $this->enrolledMember();
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.available', false)
            ->assertJsonPath('data.label', 'Belum tersedia')->assertJsonPath('data.courses', []);
        $this->ask($member)->assertStatus(503)->assertExactJson(['error' => ['code' => 'ai_unavailable', 'message' => 'Asisten Belajar belum tersedia.']]);

        // Flag Asisten Belajar saja (tanpa AI_ENABLED dan API key) tetap mati; chatbot publik tidak ikut menyalakan.
        config(['ai.use_cases.lms_assistant.enabled' => true, 'chatbot.enabled' => true, 'chatbot.api_key' => 'sk-test']);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant')->assertJsonPath('data.enabled', true)->assertJsonPath('data.available', false);
        $this->ask($member)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable');
        config(['ai.enabled' => true]);
        $this->ask($member)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable'); // belum ada ANTHROPIC_API_KEY

        $this->assertCount(0, $this->model->calls);
        Http::assertNothingSent();
        $this->assertSame(0, ChatLog::count());
        $this->assertSame(0, AiInsight::count());

        // Wajib login; flag asisten tidak memengaruhi chatbot publik.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/assistant')->assertUnauthorized();
        $this->postJson('/api/v1/me/assistant/messages', ['question' => 'halo'])->assertUnauthorized();
        config(['chatbot.enabled' => false]);
        $this->getJson('/api/v1/chat/status')->assertOk()->assertJson(['enabled' => false]);
    }

    public function test_answers_only_from_published_material_with_sources_and_no_personal_data(): void
    {
        $this->enable();
        $this->useModel($this->citedModel());
        [$member, $enrollment] = $this->enrolledMember();
        $official = ['enrollments', 'registrations', 'attendances', 'certificates', 'quiz_attempts', 'assignment_submissions', 'lesson_progress', 'members', 'invoices'];
        $writes = [];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b('.implode('|', $official).')\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $res = $this->ask($member, ['question' => "Berapa lapisan   model OSI?\nBalas ke budi@mail.com"])->assertCreated();
        $this->assertSame([], $writes, 'Asisten Belajar tidak boleh menulis data resmi (termasuk progres materi)');

        // Isi permintaan ke model: aturan gaya pertama, prompt asisten di-cache, dokumen hanya lesson terbit yang sudah dibuka.
        $call = $this->model->calls[0];
        $this->assertSame(AiPrompt::STYLE_RULES, $call['system'][0]['text']);
        $this->assertSame(AiLearningAssistantService::SYSTEM_PROMPT, $call['system'][1]['text']);
        $this->assertTrue($call['system'][1]['cache']);
        $this->assertSame('claude-opus-5-5', $call['options']['model']);
        $this->assertArrayNotHasKey('json_schema', $call['options']); // citations tidak bisa digabung structured output
        $content = $call['messages'][0]['content'];
        $docs = array_values(array_filter($content, fn ($b) => $b['type'] === 'document'));
        $this->assertSame(['Dasar Jaringan: Model OSI', 'Pengalamatan: IPv4'], array_column($docs, 'title'));
        $this->assertSame(['enabled' => true], $docs[0]['citations']);
        $this->assertSame('text/plain', $docs[0]['source']['mediaType']);
        $this->assertArrayNotHasKey('cacheControl', $docs[0]);
        $this->assertSame(['type' => 'ephemeral'], $docs[1]['cacheControl']);
        $this->assertStringContainsString("Model OSI\nModel OSI memiliki 7 lapisan.\n- Physical\n- Data link", $docs[0]['source']['data']);
        $this->assertStringContainsString('32 bit & ditulis', $docs[1]['source']['data']);
        $payload = json_encode($call, JSON_UNESCAPED_UNICODE);
        foreach (['RAHASIA', 'BESOK', 'Kunci: jawaban', 'KUNCI TUGAS', 'andi@vmes.id', '0812-3456-7890', 'budi@mail.com', 'Budi Santoso', 'peserta@example.com', 'CCNA-2611'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload, "tidak boleh terkirim: $secret");
        }
        $this->assertSame("Pertanyaan peserta:\nBerapa lapisan model OSI? Balas ke [email]", end($content)['text']);

        // Jawaban: bersih dari em/en dash, bersumber, dengan nomor sumber per segmen.
        $data = $res->json('data');
        $this->assertTrue($data['resolved']);
        $this->assertStringNotContainsString(self::EM, $data['answer']);
        $this->assertStringNotContainsString(self::EN, $data['answer']);
        $this->assertStringContainsString('lapisan 1-7', $data['answer']);
        $this->assertSame([[1], [2], [1]], array_column($data['segments'], 'refs'));
        $this->assertSame([$this->osi->id, $this->ip->id], array_column($data['sources'], 'lessonId'));
        $this->assertSame('Dasar Jaringan', $data['sources'][0]['module']);
        $this->assertSame(['Model OSI memiliki 7 lapisan.'], $data['sources'][0]['quotes']);
        $this->assertSame('ccna-online', $data['course']);

        // Tersimpan sebagai insight milik enrollment, tanpa organisasi, dengan referensi ID + hash saja.
        $insight = AiInsight::query()->sole();
        $this->assertSame('lms_assistant', $insight->use_case);
        $this->assertSame(AiInsight::STATUS_APPROVED, $insight->status);
        $this->assertTrue($insight->subject->is($enrollment));
        $this->assertNull($insight->organization_id);
        $this->assertSame('2026-11-17.2', $insight->prompt_version);
        $this->assertSame(2, $insight->output['lessons_used']);
        $refs = collect($insight->data_reference['items']);
        $this->assertEqualsCanonicalizing([$this->osi->id, $this->ip->id], $refs->where('type', (new Lesson)->getMorphClass())->pluck('id')->all());
        $this->assertStringNotContainsString('7 lapisan', json_encode($insight->data_reference));
        $this->assertSame($insight->id, AiUsageRecord::query()->sole()->ai_insight_id);
    }

    public function test_only_learners_with_access_can_ask_and_lesson_must_belong_to_the_material(): void
    {
        $this->enable();
        [$member] = $this->enrolledMember();
        $outsider = User::factory()->create();

        $this->ask($outsider)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->ask($member, ['course' => 'tidak-ada'])->assertNotFound();
        $this->ask($member, ['lessonId' => $this->quiz->id])->assertUnprocessable()->assertJsonValidationErrors('lessonId');
        $this->ask($member, ['lessonId' => $this->draft->id])->assertUnprocessable();
        $this->ask($member, ['question' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('question');

        // Di luar masa akses materi: aturan LMS yang sama (403 dengan alasan).
        $this->course->update(['access_days_after' => 5]);
        Carbon::setTestNow('2026-12-30 09:00:00');
        $this->ask($member)->assertForbidden();

        $this->assertCount(0, $this->model->calls);
        $this->assertSame(0, AiInsight::count());
    }

    public function test_questions_outside_the_material_are_unresolved_and_point_to_the_forum(): void
    {
        $this->enable();
        [$member] = $this->enrolledMember();

        // Model menyatakan tidak ada di materi.
        $this->useModel(new FakeChatModel(AiLearningAssistantService::NOT_FOUND_MARKER.' Materi belum membahas BGP, tetapi BGP adalah protokol antar AS.'));
        $a = $this->ask($member, ['question' => 'Apa itu BGP?'])->assertCreated()->json('data');
        $this->assertFalse($a['resolved']);
        $this->assertSame([], $a['sources']);
        $this->assertStringContainsString('forum diskusi kelas', $a['answer']);
        $this->assertStringNotContainsString('antar AS', $a['answer']); // teks model tanpa sumber tidak ditampilkan

        // Model menjawab tanpa kutipan: disembunyikan, dianggap tidak terjawab.
        $this->useModel(new FakeChatModel('BGP adalah protokol routing antar AS.'));
        $b = $this->ask($member, ['question' => 'Apa itu BGP?'])->assertCreated()->json('data');
        $this->assertFalse($b['resolved']);
        $this->assertStringNotContainsString('antar AS', $b['answer']);
        $this->assertSame(['not_in_material', 'uncited'], AiInsight::query()->orderBy('id')->get()->map(fn ($i) => $i->output['reason'])->all());
        $this->assertStringNotContainsString('antar AS', json_encode(AiInsight::query()->pluck('output')));

        // Course tanpa materi teks: tidak memanggil model sama sekali.
        $calls = count($this->model->calls);
        Lesson::query()->whereIn('id', [$this->osi->id, $this->ip->id])->update(['body' => null]);
        $c = $this->ask($member)->assertCreated()->json('data');
        $this->assertFalse($c['resolved']);
        $this->assertCount($calls, $this->model->calls);
        $this->assertSame('no_material', AiInsight::query()->latest('id')->first()->output['reason']);
    }

    public function test_per_participant_daily_limit_and_model_failures_fall_back(): void
    {
        $this->enable();
        config(['ai.use_cases.lms_assistant.per_user_daily' => 2]);
        $this->useModel($this->citedModel());
        [$member] = $this->enrolledMember();
        [$other] = $this->enrolledMember('lain@example.com', 'Siti');

        $this->ask($member)->assertCreated();
        $this->ask($member)->assertCreated();
        $this->ask($member)->assertStatus(429)->assertJsonPath('error.code', 'ai_limit');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant')->assertJsonPath('data.remaining', 0)->assertJsonPath('data.usedToday', 2);
        $this->app['auth']->forgetGuards();
        $this->ask($other)->assertCreated(); // kuota per peserta
        $this->app['auth']->forgetGuards();
        Carbon::setTestNow('2026-11-19 08:00:00');
        $this->ask($member)->assertCreated();
        $this->assertCount(4, $this->model->calls);

        // Galat dan penolakan model: 503 ai_failed, tidak ada jawaban tersimpan, kejadian tercatat di pemakaian.
        $before = AiInsight::count();
        $this->useModel(new FakeChatModel(fail: true));
        $this->ask($other)->assertStatus(503)->assertJsonPath('error.code', 'ai_failed');
        $this->useModel(new FakeChatModel(refuse: true));
        $this->ask($other)->assertStatus(503)->assertJsonPath('error.code', 'ai_failed');
        $this->assertSame($before, AiInsight::count());
        $this->assertSame(['error', 'refused'], AiUsageRecord::query()->whereIn('status', ['error', 'refused'])->orderBy('id')->pluck('status')->all());

        // Jeda admin = fitur kembali "Belum tersedia".
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);
        app(AiGovernance::class)->pause('lms_assistant', $admin);
        $this->ask($other)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable');
    }

    public function test_status_history_and_feedback_belong_to_the_asking_participant_only(): void
    {
        $this->enable();
        $this->useModel($this->citedModel());
        [$member] = $this->enrolledMember();
        [$other] = $this->enrolledMember('lain@example.com', 'Siti');

        $status = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant')->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.label', 'Dibuat AI')->assertJsonPath('data.remaining', 20)->json('data');
        $this->assertSame('ccna-online', $status['courses'][0]['slug']);
        $this->assertSame([$this->osi->id, $this->ip->id], array_column($status['courses'][0]['lessons'], 'id'));
        // Mode ringkas untuk tautan di halaman course/lesson: tanpa daftar course.
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant?brief=1')->assertJsonPath('data.available', true)->assertJsonPath('data.courses', []);

        $id = $this->ask($member, ['lessonId' => $this->ip->id])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant/messages?course=ccna-online')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.feedback', null);
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => true])->assertOk();
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/assistant/messages')->assertJsonPath('data.0.feedback', true);
        $this->app['auth']->forgetGuards();

        // Peserta lain dan trainer kelas tidak melihat pertanyaan orang lain.
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/me/assistant/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => false])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $trainerUser = User::factory()->create(['role' => User::ROLE_TRAINER]);
        $trainer = Trainer::query()->create(['name' => 'Andi', 'slug' => 'andi', 'user_id' => $trainerUser->id]);
        TrainingSchedule::query()->where('code', 'CCNA-2611')->firstOrFail()->trainers()->attach($trainer->id, ['role' => 'lead']);
        $this->actingAs($trainerUser, 'sanctum')->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => false])->assertNotFound();
    }

    public function test_long_material_prioritizes_the_open_lesson_and_records_truncation(): void
    {
        $this->enable();
        $this->useModel($this->citedModel());
        [$member] = $this->enrolledMember();
        $this->osi->update(['body' => '<p>'.str_repeat('Lapisan OSI. ', 200).'</p>']); // sekitar 2600 karakter
        config(['ai.use_cases.lms_assistant.max_material_chars' => 1000]);

        $this->ask($member, ['lessonId' => $this->ip->id])->assertCreated();
        $docs = array_values(array_filter($this->model->calls[0]['messages'][0]['content'], fn ($b) => $b['type'] === 'document'));
        $this->assertSame(['Pengalamatan: IPv4'], array_column($docs, 'title'));
        $out = AiInsight::query()->sole()->output;
        $this->assertTrue($out['material_truncated']);
        $this->assertSame(1, $out['lessons_used']);
        $this->assertSame(2, $out['lessons_total']);
        // Indeks sitasi di luar dokumen yang dikirim diabaikan (fake mengutip dokumen 1 yang tidak ada).
        $this->assertSame([$this->ip->id], array_column(AiInsight::query()->sole()->output['sources'], 'lessonId'));
    }

    public function test_evaluate_reports_quality_citation_coverage_unresolved_rate_and_forum_baseline(): void
    {
        $this->enable();
        [$member, $enrollment] = $this->enrolledMember();
        $this->useModel($this->citedModel());
        $id = $this->ask($member)->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->useModel(new FakeChatModel('Jawaban tanpa sumber.'));
        $this->ask($member);
        $this->app['auth']->forgetGuards();
        $this->useModel(new FakeChatModel(fail: true));
        $this->ask($member);
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/ai-insights/$id/feedback", ['helpful' => true])->assertOk();

        $q = DiscussionPost::query()->create(['course_id' => $this->course->id, 'user_id' => $member->id, 'body' => 'Tanya subnet']);
        Carbon::setTestNow('2026-11-18 13:00:00');
        DiscussionPost::query()->create(['course_id' => $this->course->id, 'user_id' => User::factory()->create()->id, 'parent_id' => $q->id, 'body' => 'Jawab', 'is_trainer' => true]);
        DiscussionPost::query()->create(['course_id' => $this->course->id, 'user_id' => $member->id, 'body' => 'Belum dijawab']);

        $m = (new LmsAssistantEvaluator)->evaluate();
        $this->assertSame(3, $m['metrics']['questions']);
        $this->assertSame(1, $m['metrics']['resolved']);
        $this->assertEquals(0.6667, $m['metrics']['unresolved_rate']);
        $this->assertEquals(0.5, $m['metrics']['citation_coverage']);
        $this->assertSame(1, $m['metrics']['uncited_hidden']);
        $this->assertSame(1, $m['metrics']['model_failures']);
        $this->assertEquals(1.0, $m['metrics']['helpful_rate']);
        $this->assertSame(2, $m['baseline']['questions']);
        $this->assertEquals(0.5, $m['baseline']['answered_rate']);
        $this->assertEquals(4.0, $m['baseline']['median_hours_to_answer']);
        $this->assertNotEmpty(array_filter($m['notes'], fn ($n) => str_contains($n, 'Umpan balik peserta baru 1')));

        Storage::fake('local');
        $this->artisan('ai:evaluate', ['usecase' => 'lms_assistant'])->assertSuccessful();
    }

    public function test_plain_text_keeps_lines_and_bullets(): void
    {
        $this->assertSame("Judul\nSatu & dua\n- A\n- B\nAkhir", AiLearningAssistantService::plainText('<h1>Judul</h1><p>Satu &amp; dua</p><script>x()</script><ul><li>A</li><li>B</li></ul><p>Akhir</p>'));
        $this->assertSame('', AiLearningAssistantService::plainText('<p> </p>'));
        $this->assertSame('', AiLearningAssistantService::plainText(null));
    }
}
