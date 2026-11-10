<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTrainer;
use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Services\LmsService;
use App\Support\LmsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Portal trainer — Materials, Assignments (penilaian), Quiz Results, Progress LMS per kelas (PRD §5.7, §5.8). */
class TrainerLmsController extends Controller
{
    use ResolvesTrainer;

    public function __construct(private readonly LmsService $lms) {}

    /** Submission yang boleh dinilai trainer: lesson milik course dari kelas yang ditugaskan. */
    private function submissionOf(Request $request, int $id): AssignmentSubmission
    {
        $t = $this->trainer($request);
        $sub = AssignmentSubmission::query()->whereKey($id)->with(['lesson.module.course', 'enrollment.schedule', 'grader'])->firstOrFail();
        abort_unless($t->schedules()->whereKey($sub->enrollment->schedule_id)->exists(), 403, 'Tugas ini bukan dari kelas Anda.');

        return $sub;
    }

    /** GET /trainer/classes/{code}/learning — course (pohon materi) + progres LMS tiap peserta. */
    public function learning(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code);
        $courses = Course::query()->published()->forSchedule($s)->with('modules.lessons.resources')->get()->map(fn (Course $c) => [
            'slug' => $c->slug, 'title' => $c->title, 'description' => $c->description, 'availableBeforeClass' => $c->available_before_class, 'accessDaysAfter' => $c->access_days_after,
            'lessonsCount' => $this->lms->publishedLessons($c)->count(),
            'modules' => $c->modules->where('is_published', true)->values()->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title,
                'lessons' => $m->lessons->where('is_published', true)->values()->map(fn (Lesson $l) => LmsPresenter::lessonRow($l, null) + [
                    'resources' => $l->resources->map(fn ($r) => LmsPresenter::resource($r))->values(),
                ]),
            ]),
        ]);

        return response()->json(['data' => ['class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title], 'courses' => $courses, 'participants' => $this->lms->classProgress($s)]]);
    }

    /** GET /trainer/classes/{code}/submissions — pengumpulan tugas kelas (yang belum dinilai dulu). */
    public function submissions(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code);
        $courseIds = Course::query()->forSchedule($s)->pluck('id');
        $subs = AssignmentSubmission::query()->with(['lesson.module.course', 'enrollment', 'grader'])
            ->whereHas('enrollment', fn ($q) => $q->where('schedule_id', $s->id))
            ->whereHas('lesson.module', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'returned' THEN 1 ELSE 2 END")->latest('submitted_at')->get();
        $tasks = Course::query()->published()->forSchedule($s)->with('modules.lessons')->get()
            ->flatMap(fn (Course $c) => $this->lms->publishedLessons($c)->where('type', 'assignment')->map(fn (Lesson $l) => ['lessonId' => $l->id, 'title' => $l->title, 'course' => $c->title, 'maxScore' => (float) ($l->config()['max_score'] ?? 100), 'dueAt' => $l->config()['due_at'] ?? null]))->values();

        return response()->json(['data' => [
            'class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title],
            'participantsCount' => $s->enrollments()->count(),
            'assignments' => $tasks,
            'submissions' => $subs->map(fn (AssignmentSubmission $x) => LmsPresenter::submission($x) + [
                'lessonId' => $x->lesson_id, 'lessonTitle' => $x->lesson->title, 'course' => $x->lesson->module->course->title, 'maxScore' => (float) ($x->lesson->config()['max_score'] ?? 100),
                'participant' => ['id' => $x->enrollment_id, 'name' => $x->enrollment->participant_name, 'email' => $x->enrollment->participant_email],
            ])->values(),
        ]]);
    }

    /** POST /trainer/submissions/{id}/grade — {score?, feedback?, status: graded|returned} */
    public function grade(Request $request, int $id): JsonResponse
    {
        $sub = $this->submissionOf($request, $id);
        $data = $request->validate(['score' => ['nullable', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:5000'], 'status' => ['required', Rule::in(['graded', 'returned'])]]);
        $sub = $this->lms->grade($sub, isset($data['score']) ? (float) $data['score'] : null, $data['feedback'] ?? null, $data['status'], $request->user());

        return response()->json(['data' => LmsPresenter::submission($sub->load('grader'))]);
    }

    /** GET /trainer/submissions/{id}/file — berkas tugas peserta. */
    public function submissionFile(Request $request, int $id): StreamedResponse
    {
        $sub = $this->submissionOf($request, $id);
        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($sub->file_path && $disk->exists($sub->file_path), 404);

        return $disk->download($sub->file_path, $sub->file_name ?: basename($sub->file_path));
    }

    /** GET /trainer/classes/{code}/quiz-results — hasil kuis per soal-kuis per peserta (percobaan terbaik + jumlah percobaan). */
    public function quizResults(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code);
        $quizzes = Course::query()->published()->forSchedule($s)->with('modules.lessons')->get()
            ->flatMap(fn (Course $c) => $this->lms->publishedLessons($c)->where('type', 'quiz')->map(fn (Lesson $l) => ['lesson' => $l, 'course' => $c->title]))->values();
        $enrollments = $s->enrollments()->orderBy('participant_name')->get();
        $attempts = QuizAttempt::query()->whereIn('enrollment_id', $enrollments->pluck('id'))->whereIn('lesson_id', $quizzes->pluck('lesson.id'))->whereNotNull('submitted_at')->get()->groupBy(fn ($a) => $a->lesson_id.'-'.$a->enrollment_id);

        return response()->json(['data' => [
            'class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title],
            'quizzes' => $quizzes->map(fn ($q) => ['lessonId' => $q['lesson']->id, 'title' => $q['lesson']->title, 'course' => $q['course'], 'kindLabel' => Lesson::QUIZ_KINDS[$q['lesson']->config()['kind'] ?? 'quiz'] ?? 'Kuis', 'passingScore' => (int) ($q['lesson']->config()['passing_score'] ?? 0), 'questionsCount' => $q['lesson']->questions()->count()])->values(),
            'participants' => $enrollments->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->participant_name,
                'results' => $quizzes->mapWithKeys(function ($q) use ($attempts, $e) {
                    $g = $attempts->get($q['lesson']->id.'-'.$e->id);
                    $best = $g?->sortByDesc('percent')->first();

                    return [$q['lesson']->id => $best ? ['percent' => $best->percent, 'passed' => $best->passed, 'attempts' => $g->count(), 'score' => (float) $best->score, 'maxScore' => (float) $best->max_score] : null];
                }),
            ])->values(),
        ]]);
    }
}
