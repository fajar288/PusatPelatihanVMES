<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\QuizAttempt;
use App\Models\Trainer;
use App\Services\LmsService;
use App\Support\IdDate;
use App\Support\LmsPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Portal peserta — My Learning / My Progress / Assignments / Results (PRD §5.8). Semua endpoint di bawah /me/learning. */
class LearningController extends Controller
{
    public function __construct(private readonly LmsService $lms) {}

    /** Lesson terbit yang menjadi bagian course (404 bila bukan). */
    private function lessonOf(Course $course, int $lessonId): Lesson
    {
        return Lesson::query()->whereKey($lessonId)->published()
            ->whereHas('module', fn ($q) => $q->where('course_id', $course->id)->where('is_published', true))
            ->with(['module', 'resources'])->firstOrFail();
    }

    /** GET /me/learning — course per enrollment dengan progres. */
    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()->ownedBy($request->user())->with('schedule.package')->latest('enrolled_at')->get()->filter(fn (Enrollment $e) => $e->canLearn());
        $items = [];
        $seen = [];
        foreach ($enrollments as $e) {
            foreach ($this->lms->coursesForEnrollment($e) as $row) {
                if (isset($seen[$row['course']->id])) {
                    continue;
                }
                $seen[$row['course']->id] = true;
                $items[] = LmsPresenter::course($row['course'], $e, $row);
            }
        }

        return response()->json(['data' => $items]);
    }

    /** GET /me/learning/{course} — pohon modul/lesson + progres. */
    public function show(Request $request, Course $course): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $course->load('modules.lessons');
        $progress = $this->lms->progressMap($course, $e);
        $summary = $this->lms->summary($course, $e);
        $next = $this->lms->publishedLessons($course)->first(fn (Lesson $l) => ! ($progress->get($l->id)?->isCompleted()) && $l->isAvailableNow());

        return response()->json(['data' => LmsPresenter::course($course, $e, $summary) + [
            'modules' => LmsPresenter::tree($course, $progress),
            'nextLesson' => $next ? ['id' => $next->id, 'title' => $next->title, 'type' => $next->type] : null,
            'requiredTotal' => $summary['required_total'],
            'requiredCompleted' => $summary['required_completed'],
        ]]);
    }

    /** GET /me/learning/{course}/lessons/{lesson} — konten lesson; mencatat "dibuka". */
    public function lesson(Request $request, Course $course, int $lesson): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $l = $this->lessonOf($course, $lesson);
        abort_unless($l->isAvailableNow(), 403, 'Materi ini tersedia mulai '.IdDate::long($l->available_from).'.');
        $p = $this->lms->open($l, $e);

        return response()->json(['data' => LmsPresenter::lesson($l, $course, $e, $p, $this->lms)]);
    }

    /** POST /me/learning/{course}/lessons/{lesson}/progress — {action: complete|video, position?, percent?, spent?} */
    public function progress(Request $request, Course $course, int $lesson): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $l = $this->lessonOf($course, $lesson);
        $data = $request->validate([
            'action' => ['required', Rule::in(['open', 'complete', 'video'])],
            'position' => ['nullable', 'integer', 'min:0'], 'percent' => ['nullable', 'integer', 'min:0', 'max:100'], 'spent' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);
        $p = match ($data['action']) {
            'complete' => $this->lms->complete($l, $e),
            'video' => $this->lms->video($l, $e, (int) ($data['position'] ?? 0), (int) ($data['percent'] ?? 0), (int) ($data['spent'] ?? 0)),
            default => $this->lms->open($l, $e),
        };

        return response()->json(['data' => ['status' => $p->status, 'videoPosition' => $p->video_position, 'videoPercent' => $p->video_percent, 'completedAt' => $p->completed_at?->toIso8601String()]]);
    }

    /** POST /me/learning/{course}/lessons/{lesson}/quiz/start */
    public function startQuiz(Request $request, Course $course, int $lesson): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $l = $this->lessonOf($course, $lesson);
        $this->lms->startQuiz($l, $e);

        return response()->json(['data' => LmsPresenter::quizState($l, $e, $this->lms)]);
    }

    /** POST /me/learning/{course}/lessons/{lesson}/quiz/submit — {answers: {question_id: jawaban}} */
    public function submitQuiz(Request $request, Course $course, int $lesson): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $l = $this->lessonOf($course, $lesson);
        $data = $request->validate(['answers' => ['present', 'array']]);
        $open = QuizAttempt::query()->where('lesson_id', $l->id)->where('enrollment_id', $e->id)->whereNull('submitted_at')->first();
        abort_unless($open, 422, 'Tidak ada percobaan kuis yang sedang berjalan. Mulai kuis terlebih dahulu.');
        $attempt = $this->lms->submitQuiz($open, $data['answers']);

        return response()->json(['data' => LmsPresenter::quizState($l, $e, $this->lms) + ['result' => LmsPresenter::attempt($attempt, $l, (bool) ($l->config()['show_answers'] ?? true))]]);
    }

    /** POST /me/learning/{course}/lessons/{lesson}/assignment — multipart: text?, file? */
    public function submitAssignment(Request $request, Course $course, int $lesson): JsonResponse
    {
        $e = $this->lms->authorize($request->user(), $course);
        $l = $this->lessonOf($course, $lesson);
        $request->validate(['text' => ['nullable', 'string', 'max:20000'], 'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,rar,jpg,jpeg,png,txt,csv,mp4']]);
        $sub = $this->lms->submitAssignment($l, $e, $request->input('text'), $request->file('file'));

        return response()->json(['data' => LmsPresenter::submission($sub->load('grader'))], 201);
    }

    /** GET /me/learning/assignments — semua tugas lintas course beserta status pengumpulan. */
    public function assignments(Request $request): JsonResponse
    {
        $rows = [];
        foreach ($this->accessibleCourses($request) as [$course, $e]) {
            foreach ($this->lms->publishedLessons($course)->where('type', 'assignment') as $l) {
                $cfg = $l->config();
                $sub = AssignmentSubmission::query()->where('lesson_id', $l->id)->where('enrollment_id', $e->id)->with('grader')->first();
                $due = ! empty($cfg['due_at']) ? Carbon::parse($cfg['due_at']) : null;
                $rows[] = [
                    'lessonId' => $l->id, 'title' => $l->title, 'course' => ['slug' => $course->slug, 'title' => $course->title], 'module' => $l->module->title,
                    'dueAt' => $due?->toIso8601String(), 'dueLabel' => $due ? IdDate::short($due).' '.$due->format('H:i') : null, 'overdue' => $due ? $due->isPast() && ! $sub : false,
                    'maxScore' => (float) ($cfg['max_score'] ?? 100), 'submission' => $sub ? LmsPresenter::submission($sub) : null,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    /** GET /me/learning/results — nilai kuis (percobaan terbaik) & tugas yang dinilai. */
    public function results(Request $request): JsonResponse
    {
        $quizzes = [];
        $tasks = [];
        foreach ($this->accessibleCourses($request) as [$course, $e]) {
            foreach ($this->lms->publishedLessons($course) as $l) {
                if ($l->type === 'quiz') {
                    $best = $this->lms->bestAttempt($l, $e);
                    $quizzes[] = ['lessonId' => $l->id, 'title' => $l->title, 'kindLabel' => Lesson::QUIZ_KINDS[$l->config()['kind'] ?? 'quiz'] ?? 'Kuis', 'course' => ['slug' => $course->slug, 'title' => $course->title],
                        'passingScore' => (int) ($l->config()['passing_score'] ?? 0), 'attempts' => $l->attempts()->where('enrollment_id', $e->id)->whereNotNull('submitted_at')->count(), 'best' => $best ? LmsPresenter::attempt($best) : null];
                } elseif ($l->type === 'assignment') {
                    $sub = AssignmentSubmission::query()->where('lesson_id', $l->id)->where('enrollment_id', $e->id)->with('grader')->first();
                    $tasks[] = ['lessonId' => $l->id, 'title' => $l->title, 'course' => ['slug' => $course->slug, 'title' => $course->title], 'maxScore' => (float) ($l->config()['max_score'] ?? 100), 'submission' => $sub ? LmsPresenter::submission($sub) : null];
                }
            }
        }

        return response()->json(['data' => ['quizzes' => $quizzes, 'assignments' => $tasks]]);
    }

    /** GET /me/learning/{course}/discussions?lesson= — forum/tanya jawab (peserta, trainer kelas, admin). */
    public function discussions(Request $request, Course $course): JsonResponse
    {
        $this->authorizeDiscussion($request, $course);
        $q = DiscussionPost::query()->where('course_id', $course->id)->whereNull('parent_id')->with(['user', 'replies.user'])
            ->when($request->filled('lesson'), fn ($w) => $w->where('lesson_id', (int) $request->query('lesson')))
            ->orderByDesc('is_pinned')->latest();

        return response()->json(['data' => $q->get()->map(fn ($p) => LmsPresenter::post($p))->values()]);
    }

    /** POST /me/learning/{course}/discussions — {body, lesson_id?, parent_id?} */
    public function storeDiscussion(Request $request, Course $course): JsonResponse
    {
        $isTrainer = $this->authorizeDiscussion($request, $course);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'lesson_id' => ['nullable', 'integer', Rule::exists('lessons', 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists('discussion_posts', 'id')->where('course_id', $course->id)],
        ]);
        $post = DiscussionPost::query()->create([
            'course_id' => $course->id, 'lesson_id' => $data['lesson_id'] ?? null, 'parent_id' => $data['parent_id'] ?? null,
            'user_id' => $request->user()->id, 'body' => $data['body'], 'is_trainer' => $isTrainer,
        ]);

        return response()->json(['data' => LmsPresenter::post($post->load('user'))], 201);
    }

    /** GET /me/learning/resources/{resource} — unduh/lihat berkas materi (butuh akses course; unduh hanya bila diizinkan). */
    public function resource(Request $request, LessonResource $resource): StreamedResponse
    {
        $lesson = $resource->lesson()->with('module.course')->firstOrFail();
        $course = $lesson->module->course;
        if (! $this->isTrainerOrAdmin($request, $course)) {
            $this->lms->authorize($request->user(), $course);
        }
        abort_unless($resource->type === 'file' && $resource->file_path, 404);
        $inline = $request->boolean('inline');
        abort_unless($inline || $resource->allow_download, 403, 'Berkas ini hanya dapat dibaca di aplikasi, tidak untuk diunduh.');
        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($disk->exists($resource->file_path), 404);
        $name = $resource->file_name ?: basename($resource->file_path);

        return $disk->response($resource->file_path, $name, ['Content-Type' => $resource->mime ?: 'application/octet-stream'], $inline ? 'inline' : 'attachment');
    }

    /** GET /me/learning/submissions/{submission}/file — berkas tugas milik sendiri. */
    public function submissionFile(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $e = Enrollment::query()->ownedBy($request->user())->whereKey($submission->enrollment_id)->exists();
        abort_unless($e, 403);
        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($submission->file_path && $disk->exists($submission->file_path), 404);

        return $disk->download($submission->file_path, $submission->file_name ?: basename($submission->file_path));
    }

    /** @return array<int, array{0:Course,1:Enrollment}> course yang bisa diakses user (unik per course) */
    private function accessibleCourses(Request $request): array
    {
        $out = [];
        $seen = [];
        $enrollments = Enrollment::query()->ownedBy($request->user())->with('schedule')->latest('enrolled_at')->get()->filter(fn (Enrollment $e) => $e->canLearn());
        foreach ($enrollments as $e) {
            foreach (Course::query()->published()->forSchedule($e->schedule)->with('modules.lessons')->get() as $c) {
                if (isset($seen[$c->id]) || ! $c->accessWindow($e->schedule)[0]) {
                    continue;
                }
                $seen[$c->id] = true;
                $out[] = [$c, $e];
            }
        }

        return $out;
    }

    /** Trainer kelas dari paket course ini, atau admin. */
    private function isTrainerOrAdmin(Request $request, Course $course): bool
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return true;
        }
        $trainer = Trainer::query()->where('user_id', $user->id)->where('is_active', true)->first();

        return $trainer !== null && $trainer->schedules()->where('package_id', $course->package_id)
            ->when($course->schedule_id, fn ($q) => $q->where('training_schedules.id', $course->schedule_id))->exists();
    }

    /** @return bool apakah penulis adalah trainer/admin */
    private function authorizeDiscussion(Request $request, Course $course): bool
    {
        if ($this->isTrainerOrAdmin($request, $course)) {
            return true;
        }
        $this->lms->authorize($request->user(), $course);

        return false;
    }
}
