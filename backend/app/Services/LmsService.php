<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\TrainingSchedule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Logika LMS (PRD §5.8): akses materi berdasarkan enrollment & periode kelas, learning progress,
 * kuis (penilaian otomatis), tugas & penilaian trainer, ringkasan progres per course/kelas.
 */
class LmsService
{
    /** Enrollment aktif milik user yang memberi akses ke course ini (paket sama / kelas khusus). */
    public function enrollmentFor(User $user, Course $course): ?Enrollment
    {
        return Enrollment::query()->ownedBy($user)->with('schedule')
            ->whereHas('schedule', fn ($q) => $q->where('package_id', $course->package_id)
                ->when($course->schedule_id, fn ($w) => $w->where('id', $course->schedule_id)))
            ->orderByRaw("CASE status WHEN 'attending' THEN 0 WHEN 'enrolled' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->latest('enrolled_at')->get()
            ->first(fn (Enrollment $e) => $e->canLearn());
    }

    /** Pastikan user boleh belajar di course; lempar 403 dengan alasan bila tidak. */
    public function authorize(User $user, Course $course): Enrollment
    {
        $enrollment = $this->enrollmentFor($user, $course);
        abort_unless($enrollment, 403, 'Anda tidak terdaftar pada kelas yang memiliki materi ini.');
        [$ok, $reason] = $course->accessWindow($enrollment->schedule);
        abort_unless($ok, 403, $reason);

        return $enrollment;
    }

    /** Course yang bisa diakses satu enrollment (urut) beserta ringkasan progres. @return Collection<int, array<string, mixed>> */
    public function coursesForEnrollment(Enrollment $enrollment): Collection
    {
        $courses = Course::query()->published()->forSchedule($enrollment->schedule)->with('modules.lessons')->get();

        return $courses->map(fn (Course $c) => ['course' => $c, 'enrollment' => $enrollment] + $this->summary($c, $enrollment));
    }

    /**
     * Ringkasan progres satu course untuk satu enrollment.
     *
     * @return array{percent:int,total:int,completed:int,required_total:int,required_completed:int,lessons_total:int,access:bool,access_reason:?string}
     */
    public function summary(Course $course, Enrollment $enrollment): array
    {
        $lessons = $this->publishedLessons($course);
        $done = $enrollment->lessonProgress()->whereIn('lesson_id', $lessons->pluck('id'))->where('status', 'completed')->pluck('lesson_id')->flip();
        $required = $lessons->where('is_required', true);
        $reqDone = $required->filter(fn (Lesson $l) => $done->has($l->id))->count();
        $base = $required->count() > 0 ? $required : $lessons;
        $baseDone = $base->filter(fn (Lesson $l) => $done->has($l->id))->count();
        [$access, $reason] = $course->accessWindow($enrollment->schedule);

        return [
            'percent' => $base->count() > 0 ? (int) round($baseDone / $base->count() * 100) : 0,
            'total' => $lessons->count(),
            'completed' => $lessons->filter(fn (Lesson $l) => $done->has($l->id))->count(),
            'required_total' => $required->count(),
            'required_completed' => $reqDone,
            'lessons_total' => $lessons->count(),
            'access' => $access,
            'access_reason' => $reason,
        ];
    }

    /** @return Collection<int, Lesson> lesson terbit dalam modul terbit, urut */
    public function publishedLessons(Course $course): Collection
    {
        $course->loadMissing('modules.lessons');

        return $course->modules->where('is_published', true)->flatMap(fn ($m) => $m->lessons->where('is_published', true))->values();
    }

    /** Status per lesson untuk satu enrollment: {lesson_id: LessonProgress}. @return Collection<int, LessonProgress> */
    public function progressMap(Course $course, Enrollment $enrollment): Collection
    {
        return $enrollment->lessonProgress()->whereIn('lesson_id', $this->publishedLessons($course)->pluck('id'))->get()->keyBy('lesson_id');
    }

    // ---------------------------------------------------------------- progres

    /** Catat lesson dibuka; materi teks/dokumen/tautan otomatis selesai saat dibuka. */
    public function open(Lesson $lesson, Enrollment $enrollment): LessonProgress
    {
        $p = LessonProgress::query()->firstOrCreate(['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id], ['opened_at' => now(), 'status' => 'in_progress']);
        $p->opened_at ??= now();
        $p->last_seen_at = now();
        if (in_array($lesson->type, ['text', 'document', 'link'], true) && ! $p->isCompleted()) {
            $p->status = 'completed';
            $p->completed_at = now();
        }
        $p->save();
        if ($enrollment->status === 'enrolled') {
            $enrollment->update(['status' => 'attending']);
        }

        return $p;
    }

    /** Posisi & persentase video (resume + tracking); selesai otomatis saat mencapai min_watch_pct. */
    public function video(Lesson $lesson, Enrollment $enrollment, int $position, int $percent, int $spent = 0): LessonProgress
    {
        $p = $this->open($lesson, $enrollment);
        $p->video_position = max(0, $position);
        $p->video_percent = max((int) $p->video_percent, min(100, max(0, $percent)));
        $p->time_spent_seconds += max(0, $spent);
        if (! $p->isCompleted() && $p->video_percent >= (int) ($lesson->config()['min_watch_pct'] ?? 80)) {
            $p->status = 'completed';
            $p->completed_at = now();
        }
        $p->save();

        return $p;
    }

    /** Tandai selesai manual (teks/dokumen/tautan/video). Kuis & tugas selesai lewat submit. */
    public function complete(Lesson $lesson, Enrollment $enrollment): LessonProgress
    {
        abort_if(in_array($lesson->type, ['quiz', 'assignment'], true), 422, 'Kuis dan tugas selesai setelah dikumpulkan.');
        $p = $this->open($lesson, $enrollment);
        if (! $p->isCompleted()) {
            $p->update(['status' => 'completed', 'completed_at' => now()]);
        }

        return $p;
    }

    private function completeByActivity(Lesson $lesson, Enrollment $enrollment): void
    {
        $p = $this->open($lesson, $enrollment);
        if (! $p->isCompleted()) {
            $p->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    // ---------------------------------------------------------------- kuis

    /** Mulai (atau lanjutkan) percobaan kuis. */
    public function startQuiz(Lesson $lesson, Enrollment $enrollment): QuizAttempt
    {
        abort_unless($lesson->type === 'quiz', 422, 'Lesson ini bukan kuis.');
        abort_if($lesson->questions()->count() === 0, 422, 'Kuis belum memiliki soal.');
        $open = QuizAttempt::query()->where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->whereNull('submitted_at')->first();
        if ($open) {
            $limit = (int) ($lesson->config()['time_limit_minutes'] ?? 0);
            if ($limit > 0 && $open->started_at->copy()->addMinutes($limit)->isPast()) {
                $this->submitQuiz($open, $open->answers ?? []); // waktu habis → nilai jawaban yang ada
            } else {
                return $open;
            }
        }
        $count = QuizAttempt::query()->where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->whereNotNull('submitted_at')->count();
        $max = (int) ($lesson->config()['max_attempts'] ?? 0);
        if ($max > 0 && $count >= $max) {
            throw ValidationException::withMessages(['attempt' => ["Batas percobaan kuis ({$max}×) sudah tercapai."]]);
        }
        $this->open($lesson, $enrollment);

        return QuizAttempt::query()->create(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id, 'attempt_no' => $count + 1, 'started_at' => now()]);
    }

    /** Kumpulkan jawaban {question_id: jawaban}; dinilai otomatis. */
    public function submitQuiz(QuizAttempt $attempt, array $answers): QuizAttempt
    {
        abort_unless($attempt->isOpen(), 422, 'Percobaan kuis ini sudah dikumpulkan.');
        $lesson = $attempt->lesson()->with('questions')->first();
        $score = 0.0;
        $max = 0.0;
        $detail = [];
        foreach ($lesson->questions as $q) {
            [$correct, $points] = $q->grade($answers[$q->id] ?? $answers[(string) $q->id] ?? null);
            $score += $points;
            $max += (float) $q->points;
            $detail[$q->id] = ['correct' => $correct, 'points' => $points];
        }
        $percent = $max > 0 ? (int) round($score / $max * 100) : 0;
        $passing = (int) ($lesson->config()['passing_score'] ?? 0);
        $attempt->update([
            'answers' => $answers, 'detail' => $detail, 'score' => $score, 'max_score' => $max, 'percent' => $percent,
            'passed' => $percent >= $passing, 'submitted_at' => now(),
        ]);
        $this->completeByActivity($lesson, $attempt->enrollment);
        AuditLog::record('quiz.submitted', $attempt, null, ['lesson' => $lesson->title, 'percent' => $percent, 'passed' => $percent >= $passing]);

        return $attempt->refresh();
    }

    /** Percobaan terbaik (persentase) seorang peserta untuk satu kuis. */
    public function bestAttempt(Lesson $lesson, Enrollment $enrollment): ?QuizAttempt
    {
        return QuizAttempt::query()->where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->whereNotNull('submitted_at')
            ->orderByDesc('percent')->orderByDesc('submitted_at')->first();
    }

    // ---------------------------------------------------------------- tugas

    /** Kumpulkan tugas (teks dan/atau berkas). Boleh kirim ulang selama belum dinilai atau dikembalikan. */
    public function submitAssignment(Lesson $lesson, Enrollment $enrollment, ?string $text, ?UploadedFile $file): AssignmentSubmission
    {
        abort_unless($lesson->type === 'assignment', 422, 'Lesson ini bukan tugas.');
        $cfg = $lesson->config();
        if (! empty($cfg['due_at']) && now()->gt($cfg['due_at'])) {
            throw ValidationException::withMessages(['submission' => ['Batas waktu pengumpulan sudah lewat.']]);
        }
        if (($text === null || trim($text) === '') && ! $file) {
            throw ValidationException::withMessages(['submission' => ['Isi jawaban atau unggah berkas.']]);
        }
        $existing = AssignmentSubmission::query()->where('lesson_id', $lesson->id)->where('enrollment_id', $enrollment->id)->first();
        if ($existing && $existing->status === 'graded') {
            throw ValidationException::withMessages(['submission' => ['Tugas sudah dinilai dan tidak bisa dikirim ulang.']]);
        }
        $data = ['text' => $text, 'status' => 'submitted', 'submitted_at' => now(), 'score' => null, 'feedback' => $existing?->feedback];
        if ($file) {
            $data['file_path'] = $file->store('lms/submissions/'.$enrollment->id, config('filesystems.default'));
            $data['file_name'] = $file->getClientOriginalName();
        }
        $sub = AssignmentSubmission::query()->updateOrCreate(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id], $data);
        $this->completeByActivity($lesson, $enrollment);
        AuditLog::record('assignment.submitted', $sub, null, ['lesson' => $lesson->title]);

        return $sub;
    }

    /** Penilaian tugas oleh trainer/admin. */
    public function grade(AssignmentSubmission $sub, ?float $score, ?string $feedback, string $status, User $grader): AssignmentSubmission
    {
        abort_unless(in_array($status, ['graded', 'returned'], true), 422);
        $max = (float) ($sub->lesson->config()['max_score'] ?? 100);
        if ($status === 'graded' && ($score === null || $score < 0 || $score > $max)) {
            throw ValidationException::withMessages(['score' => ["Nilai harus 0–{$max}."]]);
        }
        $before = $sub->only(['score', 'feedback', 'status']);
        $sub->update(['score' => $status === 'graded' ? $score : null, 'feedback' => $feedback, 'status' => $status, 'graded_by' => $grader->id, 'graded_at' => now()]);
        AuditLog::record('assignment.graded', $sub, $before, $sub->only(['score', 'feedback', 'status']), $grader);

        return $sub->refresh();
    }

    // ---------------------------------------------------------------- trainer / monitoring

    /** Progres LMS seluruh peserta satu kelas. @return array<int, array<string, mixed>> */
    public function classProgress(TrainingSchedule $schedule): array
    {
        $courses = Course::query()->published()->forSchedule($schedule)->with('modules.lessons')->get();
        $lessons = $courses->flatMap(fn (Course $c) => $this->publishedLessons($c));
        $quizIds = $lessons->where('type', 'quiz')->pluck('id');
        $taskIds = $lessons->where('type', 'assignment')->pluck('id');
        $rows = [];
        foreach ($schedule->enrollments()->with(['lessonProgress', 'quizAttempts', 'submissions'])->get() as $e) {
            $done = $e->lessonProgress->where('status', 'completed')->pluck('lesson_id')->flip();
            $required = $lessons->where('is_required', true);
            $base = $required->count() > 0 ? $required : $lessons;
            $best = $e->quizAttempts->whereNotNull('submitted_at')->whereIn('lesson_id', $quizIds)->groupBy('lesson_id')->map(fn ($g) => $g->max('percent'));
            $rows[] = [
                'enrollment_id' => $e->id,
                'name' => $e->participant_name,
                'email' => $e->participant_email,
                'status' => $e->status,
                'attendance_percent' => $e->attendancePercent(),
                'lessons_completed' => $lessons->filter(fn (Lesson $l) => $done->has($l->id))->count(),
                'lessons_total' => $lessons->count(),
                'percent' => $base->count() > 0 ? (int) round($base->filter(fn (Lesson $l) => $done->has($l->id))->count() / $base->count() * 100) : 0,
                'quiz_done' => $best->count(),
                'quiz_total' => $quizIds->count(),
                'quiz_avg' => $best->count() > 0 ? (int) round($best->avg()) : null,
                'assignments_submitted' => $e->submissions->whereIn('lesson_id', $taskIds)->count(),
                'assignments_total' => $taskIds->count(),
                'assignments_graded' => $e->submissions->whereIn('lesson_id', $taskIds)->where('status', 'graded')->count(),
                'last_seen_at' => $e->lessonProgress->max('last_seen_at')?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
