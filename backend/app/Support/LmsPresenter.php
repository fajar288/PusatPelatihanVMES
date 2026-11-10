<?php

namespace App\Support;

use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonResource;
use App\Models\QuizAttempt;
use App\Services\LmsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Bentuk JSON LMS untuk portal peserta & trainer (dipakai LearningController/TrainerController). */
final class LmsPresenter
{
    /** Kartu course + progres (list "My Learning"). @param array<string, mixed> $summary */
    public static function course(Course $c, Enrollment $e, array $summary): array
    {
        $s = $e->schedule;

        return [
            'slug' => $c->slug,
            'title' => $c->title,
            'description' => $c->description,
            'thumbnail' => Media::url($c->thumbnail),
            'modulesCount' => $c->modules->where('is_published', true)->count(),
            'lessonsCount' => $summary['lessons_total'],
            'completed' => $summary['completed'],
            'percent' => $summary['percent'],
            'access' => $summary['access'],
            'accessReason' => $summary['access_reason'],
            'enrollmentId' => $e->id,
            'class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'dateLabel' => IdDate::long($s->start_date), 'endLabel' => IdDate::long($s->end_date), 'status' => $s->status],
        ];
    }

    /** Pohon modul → lesson dengan status progres. @param Collection<int, LessonProgress> $progress */
    public static function tree(Course $c, Collection $progress): array
    {
        return $c->modules->where('is_published', true)->values()->map(function ($m) use ($progress) {
            $lessons = $m->lessons->where('is_published', true)->values();
            $done = $lessons->filter(fn (Lesson $l) => $progress->get($l->id)?->isCompleted())->count();

            return [
                'id' => $m->id,
                'title' => $m->title,
                'description' => $m->description,
                'lessonsCount' => $lessons->count(),
                'completed' => $done,
                'isCompleted' => $lessons->count() > 0 && $done === $lessons->count(),
                'lessons' => $lessons->map(fn (Lesson $l) => self::lessonRow($l, $progress->get($l->id)))->values(),
            ];
        })->values()->all();
    }

    public static function lessonRow(Lesson $l, ?LessonProgress $p): array
    {
        return [
            'id' => $l->id,
            'title' => $l->title,
            'type' => $l->type,
            'typeLabel' => Lesson::TYPES[$l->type] ?? $l->type,
            'durationMinutes' => $l->duration_minutes,
            'isRequired' => $l->is_required,
            'available' => $l->isAvailableNow(),
            'availableFrom' => $l->available_from?->toDateString(),
            'status' => $p?->status ?? 'not_started',
            'completedAt' => $p?->completed_at?->toIso8601String(),
        ];
    }

    /** Detail lesson untuk halaman belajar. */
    public static function lesson(Lesson $l, Course $c, Enrollment $e, ?LessonProgress $p, LmsService $lms): array
    {
        $cfg = $l->config();
        $all = $lms->publishedLessons($c);
        $idx = $all->search(fn (Lesson $x) => $x->id === $l->id);
        $nav = fn (?Lesson $x) => $x ? ['id' => $x->id, 'title' => $x->title, 'type' => $x->type] : null;
        $out = [
            'id' => $l->id,
            'title' => $l->title,
            'type' => $l->type,
            'typeLabel' => Lesson::TYPES[$l->type] ?? $l->type,
            'body' => $l->body,
            'durationMinutes' => $l->duration_minutes,
            'isRequired' => $l->is_required,
            'module' => ['id' => $l->module->id, 'title' => $l->module->title],
            'course' => ['slug' => $c->slug, 'title' => $c->title],
            'prev' => $nav($idx !== false && $idx > 0 ? $all[$idx - 1] : null),
            'next' => $nav($idx !== false && $idx < $all->count() - 1 ? $all[$idx + 1] : null),
            'progress' => ['status' => $p?->status ?? 'not_started', 'videoPosition' => $p?->video_position ?? 0, 'videoPercent' => $p?->video_percent ?? 0, 'completedAt' => $p?->completed_at?->toIso8601String()],
            'resources' => $l->resources->map(fn (LessonResource $r) => self::resource($r))->values(),
        ];
        if ($l->type === 'video') {
            $out['video'] = [
                'url' => $cfg['video_url'] ?? null,
                'embedUrl' => Lesson::videoEmbedUrl($cfg['video_url'] ?? null),
                'provider' => Lesson::videoProvider($cfg['video_url'] ?? null),
                'durationSeconds' => $cfg['duration_seconds'] ?? null,
                'thumbnail' => Media::url($cfg['thumbnail'] ?? null),
                'minWatchPct' => (int) ($cfg['min_watch_pct'] ?? 80),
            ];
        }
        if ($l->type === 'link') {
            $out['link'] = ['url' => $cfg['url'] ?? null];
        }
        if ($l->type === 'quiz') {
            $out['quiz'] = self::quizState($l, $e, $lms);
        }
        if ($l->type === 'assignment') {
            $sub = AssignmentSubmission::query()->where('lesson_id', $l->id)->where('enrollment_id', $e->id)->first();
            $due = ! empty($cfg['due_at']) ? Carbon::parse($cfg['due_at']) : null;
            $out['assignment'] = [
                'dueAt' => $due?->toIso8601String(),
                'dueLabel' => $due ? IdDate::long($due).' '.$due->format('H:i') : null,
                'overdue' => $due ? $due->isPast() : false,
                'maxScore' => (float) ($cfg['max_score'] ?? 100),
                'allowFile' => (bool) ($cfg['allow_file'] ?? true),
                'allowText' => (bool) ($cfg['allow_text'] ?? true),
                'submission' => $sub ? self::submission($sub) : null,
            ];
        }

        return $out;
    }

    public static function resource(LessonResource $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'type' => $r->type,
            'kind' => $r->kind(),
            'fileName' => $r->file_name,
            'size' => $r->size,
            'sizeLabel' => $r->size ? self::bytes((int) $r->size) : null,
            'url' => $r->type === 'link' ? $r->url : null,
            'allowDownload' => $r->allow_download,
        ];
    }

    /** Keadaan kuis bagi peserta: konfigurasi, percobaan terbuka (dengan soal tanpa kunci), riwayat. */
    public static function quizState(Lesson $l, Enrollment $e, LmsService $lms): array
    {
        $cfg = $l->config();
        $attempts = $l->attempts()->where('enrollment_id', $e->id)->orderBy('attempt_no')->get();
        $open = $attempts->first(fn (QuizAttempt $a) => $a->isOpen());
        $limit = (int) ($cfg['time_limit_minutes'] ?? 0);
        if ($open && $limit > 0 && $open->started_at->copy()->addMinutes($limit)->isPast()) {
            $open = $lms->submitQuiz($open, $open->answers ?? []);
            $attempts = $l->attempts()->where('enrollment_id', $e->id)->orderBy('attempt_no')->get();
            $open = null;
        }
        $used = $attempts->whereNotNull('submitted_at')->count();
        $max = (int) ($cfg['max_attempts'] ?? 0);
        $best = $lms->bestAttempt($l, $e);

        return [
            'kind' => $cfg['kind'] ?? 'quiz',
            'kindLabel' => Lesson::QUIZ_KINDS[$cfg['kind'] ?? 'quiz'] ?? 'Kuis',
            'timeLimitMinutes' => $limit ?: null,
            'passingScore' => (int) ($cfg['passing_score'] ?? 0),
            'maxAttempts' => $max ?: null,
            'attemptsUsed' => $used,
            'canStart' => $open === null && ($max === 0 || $used < $max) && $l->questions()->exists(),
            'questionsCount' => $l->questions()->count(),
            'showAnswers' => (bool) ($cfg['show_answers'] ?? true),
            'open' => $open ? [
                'id' => $open->id,
                'attemptNo' => $open->attempt_no,
                'startedAt' => $open->started_at->toIso8601String(),
                'deadline' => $limit > 0 ? $open->started_at->copy()->addMinutes($limit)->toIso8601String() : null,
                'answers' => $open->answers ?? (object) [],
                'questions' => $l->questions->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'question' => $q->question, 'options' => $q->optionList(), 'points' => (float) $q->points])->values(),
            ] : null,
            'best' => $best ? self::attempt($best, $l, (bool) ($cfg['show_answers'] ?? true)) : null,
            'attempts' => $attempts->whereNotNull('submitted_at')->map(fn (QuizAttempt $a) => self::attempt($a, $l, false))->values(),
        ];
    }

    public static function attempt(QuizAttempt $a, ?Lesson $l = null, bool $withReview = false): array
    {
        $out = [
            'id' => $a->id,
            'attemptNo' => $a->attempt_no,
            'score' => $a->score !== null ? (float) $a->score : null,
            'maxScore' => $a->max_score !== null ? (float) $a->max_score : null,
            'percent' => $a->percent,
            'passed' => $a->passed,
            'startedAt' => $a->started_at->toIso8601String(),
            'submittedAt' => $a->submitted_at?->toIso8601String(),
            'submittedLabel' => $a->submitted_at ? IdDate::short($a->submitted_at).' '.$a->submitted_at->format('H:i') : null,
        ];
        if ($withReview && $l) {
            $out['review'] = $l->questions->map(fn ($q) => [
                'id' => $q->id, 'question' => $q->question, 'type' => $q->type, 'options' => $q->optionList(),
                'given' => $a->answers[$q->id] ?? $a->answers[(string) $q->id] ?? null,
                'answer' => $q->answer, 'correct' => (bool) ($a->detail[$q->id]['correct'] ?? $a->detail[(string) $q->id]['correct'] ?? false),
                'points' => (float) ($a->detail[$q->id]['points'] ?? $a->detail[(string) $q->id]['points'] ?? 0), 'maxPoints' => (float) $q->points, 'explanation' => $q->explanation,
            ])->values();
        }

        return $out;
    }

    public static function submission(AssignmentSubmission $s): array
    {
        return [
            'id' => $s->id,
            'text' => $s->text,
            'fileName' => $s->file_name,
            'hasFile' => (bool) $s->file_path,
            'status' => $s->status,
            'statusLabel' => AssignmentSubmission::STATUSES[$s->status] ?? $s->status,
            'submittedAt' => $s->submitted_at->toIso8601String(),
            'submittedLabel' => IdDate::short($s->submitted_at).' '.$s->submitted_at->format('H:i'),
            'score' => $s->score !== null ? (float) $s->score : null,
            'feedback' => $s->feedback,
            'gradedAt' => $s->graded_at?->toIso8601String(),
            'gradedBy' => $s->grader?->name,
        ];
    }

    public static function post(DiscussionPost $p): array
    {
        return [
            'id' => $p->id,
            'body' => $p->body,
            'author' => $p->user?->name ?? 'Pengguna',
            'isTrainer' => $p->is_trainer,
            'isPinned' => $p->is_pinned,
            'lessonId' => $p->lesson_id,
            'createdAt' => $p->created_at->toIso8601String(),
            'createdLabel' => IdDate::short($p->created_at).' '.$p->created_at->format('H:i'),
            'replies' => $p->relationLoaded('replies') ? $p->replies->map(fn ($r) => self::post($r))->values() : [],
        ];
    }

    public static function bytes(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    }
}
