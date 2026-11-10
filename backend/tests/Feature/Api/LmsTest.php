<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\QuizQuestion;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 3 tahap 1 — LMS: akses materi, progres, kuis, tugas, forum, portal trainer. */
class LmsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Lesson $text;

    private Lesson $video;

    private Lesson $quiz;

    private Lesson $task;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00'); // kelas CCNA-2611 berlangsung 17–21 Nov 2026
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(config('filesystems.default'));
        $this->buildCourse();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buildCourse(): void
    {
        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $this->course = Course::query()->create(['package_id' => $schedule->package_id, 'title' => 'CCNA Online Course', 'slug' => 'ccna-online', 'is_published' => true]);
        $m = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Modul 1', 'sort_order' => 1]);
        $this->text = Lesson::query()->create(['module_id' => $m->id, 'title' => 'Pengantar', 'type' => 'text', 'body' => '<p>Halo</p>', 'sort_order' => 1]);
        $this->video = Lesson::query()->create(['module_id' => $m->id, 'title' => 'Video OSI', 'type' => 'video', 'settings' => ['video_url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'min_watch_pct' => 80], 'sort_order' => 2]);
        $this->quiz = Lesson::query()->create(['module_id' => $m->id, 'title' => 'Post-test', 'type' => 'quiz', 'settings' => ['kind' => 'post_test', 'passing_score' => 60, 'max_attempts' => 2], 'sort_order' => 3]);
        QuizQuestion::query()->create(['lesson_id' => $this->quiz->id, 'type' => 'single', 'question' => 'Layer 3 OSI?', 'options' => [['key' => 'a', 'text' => 'Data link'], ['key' => 'b', 'text' => 'Network']], 'answer' => ['b'], 'points' => 1, 'sort_order' => 1]);
        QuizQuestion::query()->create(['lesson_id' => $this->quiz->id, 'type' => 'multiple', 'question' => 'Protokol L4?', 'options' => [['key' => 'a', 'text' => 'TCP'], ['key' => 'b', 'text' => 'IP'], ['key' => 'c', 'text' => 'UDP']], 'answer' => ['a', 'c'], 'points' => 2, 'sort_order' => 2]);
        QuizQuestion::query()->create(['lesson_id' => $this->quiz->id, 'type' => 'true_false', 'question' => 'IP bersifat connectionless', 'answer' => ['true'], 'points' => 1, 'sort_order' => 3]);
        $this->task = Lesson::query()->create(['module_id' => $m->id, 'title' => 'Tugas subnetting', 'type' => 'assignment', 'settings' => ['max_score' => 100], 'sort_order' => 4]);
    }

    private function enrolledMember(string $email = 'peserta@example.com'): array
    {
        $member = User::factory()->create(['email' => $email]);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $member->name, 'email' => $email, 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->latest('id')->first();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $admin);
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $admin);
        $this->app['auth']->forgetGuards();

        return [$member, Enrollment::query()->where('registration_id', $reg->id)->first()];
    }

    private function trainerUser(): User
    {
        $user = User::factory()->create(['email' => 'trainer@example.com', 'role' => User::ROLE_TRAINER]);
        $trainer = Trainer::query()->create(['name' => 'Andi', 'slug' => 'andi', 'user_id' => $user->id]);
        TrainingSchedule::query()->where('code', 'CCNA-2611')->first()->trainers()->attach($trainer->id, ['role' => 'lead']);

        return $user;
    }

    public function test_access_requires_enrollment_and_class_period(): void
    {
        $this->getJson('/api/v1/me/learning')->assertUnauthorized();

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/me/learning')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertForbidden();
        $this->app['auth']->forgetGuards();

        [$member] = $this->enrolledMember();
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'ccna-online')->assertJsonPath('data.0.lessonsCount', 4)->assertJsonPath('data.0.percent', 0)->assertJsonPath('data.0.access', true);

        // sebelum kelas mulai & tidak diizinkan dibuka lebih awal → ditolak dengan alasan
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertForbidden()->assertJsonFragment(['message' => 'Materi dapat dibuka mulai 17 November 2026.']);
        $this->course->update(['available_before_class' => true]);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertOk()->assertJsonPath('data.modules.0.lessons.0.status', 'not_started');

        // masa akses pasca-kelas habis
        Carbon::setTestNow('2026-12-30 09:00:00');
        $this->course->update(['access_days_after' => 30]);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertForbidden();
        $this->course->update(['access_days_after' => null]);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertOk();

        // course tidak terbit → tidak terdaftar
        $this->course->update(['is_published' => false]);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_lesson_progress_text_and_video(): void
    {
        [$member, $enrollment] = $this->enrolledMember();
        $base = '/api/v1/me/learning/ccna-online/lessons';

        // membuka materi teks = selesai; enrollment menjadi "attending"
        $this->actingAs($member, 'sanctum')->getJson("$base/{$this->text->id}")->assertOk()
            ->assertJsonPath('data.progress.status', 'completed')->assertJsonPath('data.next.id', $this->video->id)->assertJsonPath('data.prev', null);
        $this->assertSame('attending', $enrollment->refresh()->status);

        // video: embed URL YouTube, resume posisi, selesai otomatis ≥ 80%
        $this->actingAs($member, 'sanctum')->getJson("$base/{$this->video->id}")->assertOk()
            ->assertJsonPath('data.video.embedUrl', 'https://www.youtube.com/embed/abcdefghijk?rel=0')->assertJsonPath('data.progress.status', 'in_progress');
        $this->actingAs($member, 'sanctum')->postJson("$base/{$this->video->id}/progress", ['action' => 'video', 'position' => 120, 'percent' => 50])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.videoPosition', 120);
        $this->actingAs($member, 'sanctum')->postJson("$base/{$this->video->id}/progress", ['action' => 'video', 'position' => 300, 'percent' => 85])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/ccna-online')->assertOk()
            ->assertJsonPath('data.percent', 50)->assertJsonPath('data.completed', 2)->assertJsonPath('data.nextLesson.id', $this->quiz->id);

        // kuis tidak bisa ditandai selesai manual
        $this->actingAs($member, 'sanctum')->postJson("$base/{$this->quiz->id}/progress", ['action' => 'complete'])->assertStatus(422);

        // lesson yang belum tersedia
        $this->text->update(['available_from' => '2026-12-01']);
        $this->actingAs($member, 'sanctum')->getJson("$base/{$this->text->id}")->assertForbidden();
    }

    public function test_quiz_is_graded_automatically_with_attempt_limit(): void
    {
        [$member] = $this->enrolledMember();
        $url = "/api/v1/me/learning/ccna-online/lessons/{$this->quiz->id}";
        $this->actingAs($member, 'sanctum')->postJson("$url/quiz/submit", ['answers' => []])->assertStatus(422);

        $start = $this->actingAs($member, 'sanctum')->postJson("$url/quiz/start")->assertOk()
            ->assertJsonPath('data.kindLabel', 'Post-test')->assertJsonPath('data.open.attemptNo', 1)->assertJsonCount(3, 'data.open.questions')->json('data.open.questions');
        $this->assertArrayNotHasKey('answer', $start[0]);
        $this->assertSame([['key' => 'true', 'text' => 'Benar'], ['key' => 'false', 'text' => 'Salah']], $start[2]['options']);
        [$q1, $q2, $q3] = array_column($start, 'id');

        // 1 + 0 + 1 dari 4 poin = 50% → belum lulus (passing 60)
        $this->actingAs($member, 'sanctum')->postJson("$url/quiz/submit", ['answers' => [$q1 => 'b', $q2 => ['a'], $q3 => 'true']])->assertOk()
            ->assertJsonPath('data.result.percent', 50)->assertJsonPath('data.result.passed', false)->assertJsonPath('data.result.review.1.correct', false)->assertJsonPath('data.attemptsUsed', 1)->assertJsonPath('data.canStart', true);

        // percobaan 2: semua benar → 100%
        $this->actingAs($member, 'sanctum')->postJson("$url/quiz/start")->assertOk()->assertJsonPath('data.open.attemptNo', 2);
        $this->actingAs($member, 'sanctum')->postJson("$url/quiz/submit", ['answers' => [$q1 => 'b', $q2 => ['c', 'a'], $q3 => 'true']])->assertOk()
            ->assertJsonPath('data.result.percent', 100)->assertJsonPath('data.result.passed', true)->assertJsonPath('data.best.percent', 100)->assertJsonPath('data.canStart', false);

        // batas 2× tercapai
        $this->actingAs($member, 'sanctum')->postJson("$url/quiz/start")->assertStatus(422)->assertJsonValidationErrors('attempt');

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/results')->assertOk()
            ->assertJsonPath('data.quizzes.0.best.percent', 100)->assertJsonPath('data.quizzes.0.attempts', 2)->assertJsonPath('data.assignments.0.submission', null);
        $this->assertDatabaseHas('lesson_progress', ['lesson_id' => $this->quiz->id, 'status' => 'completed']);
    }

    public function test_assignment_submission_and_trainer_grading(): void
    {
        [$member, $enrollment] = $this->enrolledMember();
        $url = "/api/v1/me/learning/ccna-online/lessons/{$this->task->id}/assignment";

        $this->actingAs($member, 'sanctum')->post($url, [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($member, 'sanctum')->post($url, ['text' => 'Jawaban saya', 'file' => UploadedFile::fake()->create('subnet.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.fileName', 'subnet.pdf');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/assignments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.submission.status', 'submitted');
        $this->app['auth']->forgetGuards();

        // trainer lain (tidak ditugaskan) ditolak; trainer kelas melihat & menilai
        $stranger = User::factory()->create(['email' => 'lain@example.com']);
        Trainer::query()->create(['name' => 'Lain', 'slug' => 'lain', 'user_id' => $stranger->id]);
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/submissions')->assertForbidden();
        $this->app['auth']->forgetGuards();

        $trainer = $this->trainerUser();
        $subId = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/submissions')->assertOk()
            ->assertJsonCount(1, 'data.submissions')->assertJsonPath('data.submissions.0.participant.id', $enrollment->id)->assertJsonCount(1, 'data.assignments')->json('data.submissions.0.id');
        $this->actingAs($trainer, 'sanctum')->get("/api/v1/trainer/submissions/$subId/file")->assertOk();
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/submissions/$subId/grade", ['status' => 'graded', 'score' => 150])->assertStatus(422);
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/submissions/$subId/grade", ['status' => 'graded', 'score' => 88, 'feedback' => 'Bagus'])->assertOk()
            ->assertJsonPath('data.score', 88)->assertJsonPath('data.status', 'graded')->assertJsonPath('data.gradedBy', $trainer->name);
        $this->app['auth']->forgetGuards();

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/learning/results')->assertOk()->assertJsonPath('data.assignments.0.submission.score', 88)->assertJsonPath('data.assignments.0.submission.feedback', 'Bagus');
        $this->actingAs($member, 'sanctum')->post($url, ['text' => 'revisi'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assignment.graded']);
    }

    public function test_trainer_sees_class_learning_progress_and_quiz_results(): void
    {
        [$member, $enrollment] = $this->enrolledMember();
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/me/learning/ccna-online/lessons/{$this->text->id}")->assertOk();
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/learning/ccna-online/lessons/{$this->quiz->id}/quiz/start")->assertOk();
        $qs = QuizQuestion::query()->where('lesson_id', $this->quiz->id)->orderBy('sort_order')->pluck('id');
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/learning/ccna-online/lessons/{$this->quiz->id}/quiz/submit", ['answers' => [$qs[0] => 'b', $qs[1] => ['a', 'c'], $qs[2] => 'false']])->assertOk();
        $this->app['auth']->forgetGuards();

        $trainer = $this->trainerUser();
        $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/learning')->assertOk()
            ->assertJsonPath('data.courses.0.slug', 'ccna-online')->assertJsonCount(4, 'data.courses.0.modules.0.lessons')
            ->assertJsonPath('data.participants.0.enrollment_id', $enrollment->id)->assertJsonPath('data.participants.0.lessons_completed', 2)->assertJsonPath('data.participants.0.percent', 50)
            ->assertJsonPath('data.participants.0.quiz_avg', 75)->assertJsonPath('data.participants.0.assignments_submitted', 0);
        $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/quiz-results')->assertOk()
            ->assertJsonPath('data.quizzes.0.lessonId', $this->quiz->id)->assertJsonPath("data.participants.0.results.{$this->quiz->id}.percent", 75)->assertJsonPath("data.participants.0.results.{$this->quiz->id}.passed", true);
    }

    public function test_discussion_thread_between_student_and_trainer(): void
    {
        [$member] = $this->enrolledMember();
        $root = $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/learning/ccna-online/discussions', ['body' => 'Apa beda TCP dan UDP?', 'lesson_id' => $this->text->id])->assertCreated()
            ->assertJsonPath('data.isTrainer', false)->json('data.id');
        $this->app['auth']->forgetGuards();

        $trainer = $this->trainerUser();
        $this->actingAs($trainer, 'sanctum')->postJson('/api/v1/me/learning/ccna-online/discussions', ['body' => 'TCP reliable, UDP tidak.', 'parent_id' => $root])->assertCreated()->assertJsonPath('data.isTrainer', true);
        $this->app['auth']->forgetGuards();

        $this->actingAs($member, 'sanctum')->getJson("/api/v1/me/learning/ccna-online/discussions?lesson={$this->text->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.replies')->assertJsonPath('data.0.replies.0.author', $trainer->name);
        $this->app['auth']->forgetGuards();

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')->postJson('/api/v1/me/learning/ccna-online/discussions', ['body' => 'hi'])->assertForbidden();
    }

    public function test_resource_download_respects_permission(): void
    {
        [$member] = $this->enrolledMember();
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('lms/resources/modul.pdf', '%PDF-1.4 test');
        $r = LessonResource::query()->create(['lesson_id' => $this->text->id, 'type' => 'file', 'title' => 'Modul 1', 'file_path' => 'lms/resources/modul.pdf', 'file_name' => 'modul.pdf', 'mime' => 'application/pdf', 'size' => 13, 'allow_download' => false]);

        $this->actingAs($member, 'sanctum')->getJson("/api/v1/me/learning/ccna-online/lessons/{$this->text->id}")->assertOk()
            ->assertJsonPath('data.resources.0.kind', 'pdf')->assertJsonPath('data.resources.0.allowDownload', false);
        $this->actingAs($member, 'sanctum')->get("/api/v1/me/learning/resources/{$r->id}?inline=1")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($member, 'sanctum')->get("/api/v1/me/learning/resources/{$r->id}")->assertForbidden();
        $r->update(['allow_download' => true]);
        $this->actingAs($member, 'sanctum')->get("/api/v1/me/learning/resources/{$r->id}")->assertOk()->assertDownload('modul.pdf');
        $this->app['auth']->forgetGuards();

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')->get("/api/v1/me/learning/resources/{$r->id}")->assertForbidden();
    }

    public function test_admin_panel_lms_pages(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        foreach (['courses', 'courses/create', 'lessons', 'lessons/create', "courses/{$this->course->slug}/edit", "lessons/{$this->quiz->id}/edit", "lessons/{$this->task->id}/edit"] as $path) {
            $this->actingAs($admin)->get("/admin/$path")->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($student)->get('/admin/courses')->assertForbidden();
    }
}
