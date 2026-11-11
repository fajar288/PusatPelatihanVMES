<?php

namespace App\Services;

use App\Models\AssessmentRubric;
use App\Models\AuditLog;
use App\Models\CompetencyAssessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LogbookEntry;
use App\Models\Trainer;
use App\Models\TrainingLogbook;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Support\IdDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Training Logbook & Progress Monitoring (PRD §5.9).
 * Logbook per pertemuan + catatan per peserta, penilaian kompetensi per rubrik program,
 * validasi → kunci (perubahan setelahnya hanya oleh admin & tercatat di audit_logs), progress overview.
 */
class MonitoringService
{
    public const LOCKED_MESSAGE = 'Catatan sudah divalidasi dan dikunci. Minta admin membuka kunci bila perlu diubah.';

    public function __construct(private readonly LmsService $lms) {}

    // ---------------------------------------------------------------- logbook

    /**
     * Simpan logbook baru / ubah yang ada.
     *
     * @param  array<string, mixed>  $data  date, topic, activities, competencies[], practice, observations, obstacles, notes, session_id
     * @param  array<int, array{enrollment_id:int, practice_status?:?string, observation?:?string}>  $entries
     * @param  UploadedFile[]  $files  dokumentasi baru
     * @param  int[]  $removeDocs  indeks dokumentasi yang dihapus
     * @param  bool  $force  admin: boleh mengubah logbook terkunci (dicatat sebagai perubahan pasca-validasi)
     */
    public function saveLogbook(TrainingSchedule $schedule, ?TrainingLogbook $logbook, array $data, array $entries, array $files, array $removeDocs, User $actor, ?Trainer $trainer = null, bool $force = false): TrainingLogbook
    {
        if ($logbook?->is_locked && ! $force) {
            throw ValidationException::withMessages(['logbook' => [self::LOCKED_MESSAGE]]);
        }
        if (! empty($data['session_id'])) {
            $dupe = TrainingLogbook::query()->where('schedule_id', $schedule->id)->where('session_id', $data['session_id'])->when($logbook, fn ($q) => $q->whereKeyNot($logbook->id))->exists();
            if ($dupe) {
                throw ValidationException::withMessages(['session_id' => ['Sesi ini sudah memiliki logbook. Ubah logbook yang ada.']]);
            }
        }

        return DB::transaction(function () use ($schedule, $logbook, $data, $entries, $files, $removeDocs, $actor, $trainer) {
            $before = $logbook ? $this->logbookSnapshot($logbook) : null;
            $docs = collect($logbook?->documentation ?? [])->values();
            $disk = Storage::disk(config('filesystems.default'));
            foreach ($removeDocs as $i) {
                if ($docs->has($i)) {
                    $disk->delete($docs[$i]['path'] ?? '');
                    $docs->forget($i);
                }
            }
            $docs = $docs->values();
            foreach ($files as $f) {
                $path = $f->store('monitoring/logbooks/'.$schedule->id, config('filesystems.default'));
                $docs->push(['path' => $path, 'name' => $f->getClientOriginalName(), 'mime' => $f->getClientMimeType(), 'size' => $f->getSize()]);
            }
            $attrs = [
                'session_id' => $data['session_id'] ?? null,
                'date' => $data['date'],
                'topic' => $data['topic'],
                'activities' => $data['activities'] ?? null,
                'competencies' => array_values(array_filter(array_map('trim', (array) ($data['competencies'] ?? [])))),
                'practice' => $data['practice'] ?? null,
                'observations' => $data['observations'] ?? null,
                'obstacles' => $data['obstacles'] ?? null,
                'notes' => $data['notes'] ?? null,
                'documentation' => $docs->all(),
            ];
            if ($logbook) {
                $logbook->update($attrs);
            } else {
                $logbook = TrainingLogbook::query()->create($attrs + ['schedule_id' => $schedule->id, 'trainer_id' => $trainer?->id, 'created_by' => $actor->id, 'status' => 'draft', 'is_locked' => false]);
            }
            $valid = $schedule->enrollments()->pluck('id')->flip();
            foreach ($entries as $en) {
                $eid = (int) ($en['enrollment_id'] ?? 0);
                if (! $valid->has($eid)) {
                    continue;
                }
                $ps = $en['practice_status'] ?? null;
                $obs = isset($en['observation']) && trim((string) $en['observation']) !== '' ? trim((string) $en['observation']) : null;
                if ($ps === null && $obs === null) {
                    LogbookEntry::query()->where('logbook_id', $logbook->id)->where('enrollment_id', $eid)->delete();

                    continue;
                }
                LogbookEntry::query()->updateOrCreate(['logbook_id' => $logbook->id, 'enrollment_id' => $eid], ['practice_status' => $ps, 'observation' => $obs]);
            }
            $logbook->load('entries');
            $action = $before === null ? 'logbook.created' : ($logbook->is_locked ? 'logbook.updated_after_validation' : 'logbook.updated');
            AuditLog::record($action, $logbook, $before, $this->logbookSnapshot($logbook), $actor);

            return $logbook;
        });
    }

    /** Validasi & kunci logbook (trainer/admin). */
    public function validateLogbook(TrainingLogbook $logbook, User $actor): TrainingLogbook
    {
        if ($logbook->isValidated()) {
            return $logbook;
        }
        $logbook->update(['status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id, 'is_locked' => true]);
        AuditLog::record('logbook.validated', $logbook, ['status' => 'draft'], ['status' => 'validated', 'locked' => true], $actor);

        return $logbook->refresh();
    }

    /** Buka kunci logbook tervalidasi (admin) dengan alasan — tercatat di audit log. */
    public function unlockLogbook(TrainingLogbook $logbook, User $actor, string $reason): TrainingLogbook
    {
        $logbook->update(['status' => 'draft', 'is_locked' => false]);
        AuditLog::record('logbook.unlocked', $logbook, ['status' => 'validated', 'locked' => true], ['status' => 'draft', 'locked' => false, 'reason' => $reason], $actor);

        return $logbook->refresh();
    }

    /** @return array<string, mixed> */
    private function logbookSnapshot(TrainingLogbook $l): array
    {
        return [
            'session_id' => $l->session_id, 'date' => $l->date?->toDateString(), 'topic' => $l->topic, 'activities' => $l->activities, 'competencies' => $l->competencies,
            'practice' => $l->practice, 'observations' => $l->observations, 'obstacles' => $l->obstacles, 'notes' => $l->notes,
            'documentation' => collect($l->documentation ?? [])->pluck('name')->all(),
            'entries' => $l->entries()->get()->map(fn ($e) => ['enrollment_id' => $e->enrollment_id, 'practice_status' => $e->practice_status, 'observation' => $e->observation])->all(),
        ];
    }

    // ---------------------------------------------------------------- penilaian kompetensi

    /** Penilaian peserta (dibuat sebagai draf kosong bila belum ada) dengan rubrik aktif program kelas. */
    public function assessmentFor(Enrollment $enrollment): CompetencyAssessment
    {
        $existing = CompetencyAssessment::query()->where('enrollment_id', $enrollment->id)->with(['rubric.criteria', 'scores'])->first();
        if ($existing) {
            return $existing;
        }
        $rubric = AssessmentRubric::activeFor($enrollment->schedule->package);

        return CompetencyAssessment::query()->create(['enrollment_id' => $enrollment->id, 'rubric_id' => $rubric->id, 'status' => 'draft', 'is_locked' => false])->load(['rubric.criteria', 'scores']);
    }

    /**
     * Simpan skor per kriteria + catatan.
     *
     * @param  array<int, array{criterion_id:int, score:?float, note?:?string}>  $scores
     */
    public function saveAssessment(Enrollment $enrollment, array $scores, ?string $summary, ?string $recommendation, User $actor, ?Trainer $trainer = null, bool $force = false): CompetencyAssessment
    {
        $a = $this->assessmentFor($enrollment);
        if ($a->is_locked && ! $force) {
            throw ValidationException::withMessages(['assessment' => [self::LOCKED_MESSAGE]]);
        }
        $criteria = $a->rubric->criteria->keyBy('id');
        foreach ($scores as $i => $s) {
            $c = $criteria->get((int) ($s['criterion_id'] ?? 0));
            if (! $c) {
                continue;
            }
            $val = $s['score'] ?? null;
            if ($val !== null && $val !== '' && ((float) $val < 0 || (float) $val > $c->max_score)) {
                throw ValidationException::withMessages(["scores.$i.score" => ["Skor {$c->name} harus 0–{$c->max_score}."]]);
            }
        }

        return DB::transaction(function () use ($a, $scores, $summary, $recommendation, $actor, $trainer, $criteria) {
            $before = $this->assessmentSnapshot($a);
            foreach ($scores as $s) {
                $cid = (int) ($s['criterion_id'] ?? 0);
                if (! $criteria->has($cid)) {
                    continue;
                }
                $val = $s['score'] ?? null;
                $a->scores()->updateOrCreate(['criterion_id' => $cid], ['score' => $val === null || $val === '' ? null : (float) $val, 'note' => $s['note'] ?? null]);
            }
            $a->recalculate();
            $a->fill(['summary' => $summary, 'recommendation' => $recommendation, 'assessed_by' => $actor->id, 'assessed_at' => now()]);
            if ($trainer) {
                $a->trainer_id = $trainer->id;
            }
            $a->save();
            AuditLog::record($a->is_locked ? 'assessment.updated_after_validation' : 'assessment.saved', $a, $before, $this->assessmentSnapshot($a), $actor);

            return $a->refresh()->load(['rubric.criteria', 'scores']);
        });
    }

    /** Validasi & kunci penilaian; mensyaratkan semua kriteria terisi. */
    public function validateAssessment(CompetencyAssessment $a, User $actor): CompetencyAssessment
    {
        if ($a->isValidated()) {
            return $a;
        }
        $a->loadMissing('rubric.criteria', 'scores');
        $missing = $a->rubric->criteria->filter(fn ($c) => $a->scores->firstWhere('criterion_id', $c->id)?->score === null);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['assessment' => ['Lengkapi skor semua kriteria sebelum validasi: '.$missing->pluck('name')->implode(', ').'.']]);
        }
        $a->recalculate();
        $a->fill(['status' => 'validated', 'validated_at' => now(), 'validated_by' => $actor->id, 'is_locked' => true])->save();
        AuditLog::record('assessment.validated', $a, ['status' => 'draft'], ['status' => 'validated', 'locked' => true, 'total_percent' => $a->total_percent, 'is_competent' => $a->is_competent], $actor);
        app(NotificationEvents::class)->assessmentValidated($a);

        return $a->refresh()->load(['rubric.criteria', 'scores']);
    }

    public function unlockAssessment(CompetencyAssessment $a, User $actor, string $reason): CompetencyAssessment
    {
        $a->update(['status' => 'draft', 'is_locked' => false]);
        AuditLog::record('assessment.unlocked', $a, ['status' => 'validated', 'locked' => true], ['status' => 'draft', 'locked' => false, 'reason' => $reason], $actor);

        return $a->refresh();
    }

    /** @return array<string, mixed> */
    private function assessmentSnapshot(CompetencyAssessment $a): array
    {
        return [
            'scores' => $a->scores()->get()->map(fn ($s) => ['criterion_id' => $s->criterion_id, 'score' => $s->score !== null ? (float) $s->score : null, 'note' => $s->note])->all(),
            'summary' => $a->summary, 'recommendation' => $a->recommendation, 'total_percent' => $a->total_percent !== null ? (float) $a->total_percent : null, 'is_competent' => $a->is_competent,
        ];
    }

    // ---------------------------------------------------------------- progress overview

    /**
     * Progress overview satu peserta (PRD §5.9): kehadiran, materi, nilai, praktik, kompetensi, catatan trainer.
     * Untuk peserta ($forParticipant) hanya logbook/penilaian yang sudah divalidasi yang ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function overview(Enrollment $e, bool $forParticipant = false): array
    {
        $e->loadMissing('schedule.package', 'schedule.sessions', 'attendances', 'lessonProgress', 'quizAttempts', 'submissions');
        $s = $e->schedule;
        $held = $s->sessions->filter(fn ($x) => $x->date->lte(today()));
        $present = $e->attendances->whereIn('status', ['present', 'late'])->count();

        $courses = Course::query()->published()->forSchedule($s)->with('modules.lessons')->get();
        $lessons = $courses->flatMap(fn (Course $c) => $this->lms->publishedLessons($c));
        $done = $e->lessonProgress->where('status', 'completed')->pluck('lesson_id')->flip();
        $required = $lessons->where('is_required', true);
        $base = $required->count() > 0 ? $required : $lessons;
        $quizIds = $lessons->where('type', 'quiz')->pluck('id');
        $taskIds = $lessons->where('type', 'assignment')->pluck('id');
        $best = $e->quizAttempts->whereNotNull('submitted_at')->whereIn('lesson_id', $quizIds)->groupBy('lesson_id')->map(fn ($g) => $g->max('percent'));
        $graded = $e->submissions->whereIn('lesson_id', $taskIds)->where('status', 'graded');
        $taskPct = $graded->map(function ($sub) use ($lessons) {
            $max = (float) ($lessons->firstWhere('id', $sub->lesson_id)?->config()['max_score'] ?? 100);

            return $max > 0 ? (float) $sub->score / $max * 100 : null;
        })->filter(fn ($v) => $v !== null);

        $logbooks = $s->logbooks()->with(['entries' => fn ($q) => $q->where('enrollment_id', $e->id), 'trainer'])->when($forParticipant, fn ($q) => $q->where('status', 'validated'))->get();
        $entries = $logbooks->flatMap(fn ($l) => $l->entries)->values();
        $assessment = CompetencyAssessment::query()->where('enrollment_id', $e->id)->with(['rubric.criteria', 'scores', 'trainer', 'assessor'])->first();
        if ($forParticipant && $assessment && ! $assessment->isValidated()) {
            $assessment = null;
        }

        return [
            'enrollment' => ['id' => $e->id, 'name' => $e->participant_name, 'email' => $e->participant_email, 'status' => $e->status, 'finalScore' => $e->final_score !== null ? (float) $e->final_score : null],
            'class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'dateLabel' => IdDate::long($s->start_date), 'endLabel' => IdDate::long($s->end_date), 'status' => $s->status],
            'attendance' => ['percent' => $e->attendancePercent(), 'present' => $present, 'held' => $held->count(), 'total' => $s->sessions->count()],
            'learning' => [
                'percent' => $base->count() > 0 ? (int) round($base->filter(fn (Lesson $l) => $done->has($l->id))->count() / $base->count() * 100) : 0,
                'completed' => $lessons->filter(fn (Lesson $l) => $done->has($l->id))->count(), 'total' => $lessons->count(),
                'courses' => $courses->map(fn (Course $c) => ['slug' => $c->slug, 'title' => $c->title] + array_intersect_key($this->lms->summary($c, $e), array_flip(['percent', 'completed', 'total'])))->values(),
                'lastSeenAt' => $e->lessonProgress->max('last_seen_at')?->toIso8601String(),
            ],
            'scores' => [
                'quizAvg' => $best->count() > 0 ? (int) round($best->avg()) : null, 'quizDone' => $best->count(), 'quizTotal' => $quizIds->count(),
                'assignmentAvg' => $taskPct->count() > 0 ? (int) round($taskPct->avg()) : null, 'assignmentsGraded' => $graded->count(), 'assignmentsSubmitted' => $e->submissions->whereIn('lesson_id', $taskIds)->count(), 'assignmentsTotal' => $taskIds->count(),
            ],
            'practice' => [
                'completed' => $entries->where('practice_status', 'completed')->count(), 'partial' => $entries->where('practice_status', 'partial')->count(), 'notDone' => $entries->where('practice_status', 'not_done')->count(),
                'logged' => $logbooks->count(),
            ],
            'competency' => $assessment ? self::assessment($assessment) : null,
            'notes' => $logbooks->filter(fn ($l) => $l->entries->isNotEmpty())->map(fn ($l) => [
                'logbookId' => $l->id, 'date' => $l->date->toDateString(), 'dateLabel' => IdDate::short($l->date), 'topic' => $l->topic, 'status' => $l->status, 'trainer' => $l->trainer?->name,
                'practiceStatus' => $l->entries->first()->practice_status, 'practiceLabel' => LogbookEntry::PRACTICE[$l->entries->first()->practice_status ?? ''] ?? null, 'observation' => $l->entries->first()->observation,
            ])->values(),
            'logbooks' => $logbooks->map(fn ($l) => ['id' => $l->id, 'date' => $l->date->toDateString(), 'dateLabel' => IdDate::short($l->date), 'topic' => $l->topic, 'competencies' => $l->competencies ?? [], 'status' => $l->status])->values(),
        ];
    }

    /** Ringkasan overview seluruh peserta satu kelas (tabel trainer/admin). @return Collection<int, array<string, mixed>> */
    public function classOverview(TrainingSchedule $schedule): Collection
    {
        $rows = collect($this->lms->classProgress($schedule))->keyBy('enrollment_id');
        $entries = LogbookEntry::query()->whereHas('logbook', fn ($q) => $q->where('schedule_id', $schedule->id))->get()->groupBy('enrollment_id');
        $assessments = CompetencyAssessment::query()->whereIn('enrollment_id', $rows->keys())->get()->keyBy('enrollment_id');

        return $rows->map(function (array $r) use ($entries, $assessments) {
            $en = $entries->get($r['enrollment_id'], collect());
            $a = $assessments->get($r['enrollment_id']);

            return $r + [
                'practice_completed' => $en->where('practice_status', 'completed')->count(),
                'practice_partial' => $en->where('practice_status', 'partial')->count(),
                'practice_not_done' => $en->where('practice_status', 'not_done')->count(),
                'notes_count' => $en->whereNotNull('observation')->count(),
                'competency_percent' => $a?->total_percent !== null ? (float) $a->total_percent : null,
                'competency_status' => $a?->status,
                'is_competent' => $a?->is_competent,
            ];
        })->values();
    }

    /** Bentuk JSON penilaian kompetensi (rubrik + skor). @return array<string, mixed> */
    public static function assessment(CompetencyAssessment $a): array
    {
        $a->loadMissing('rubric.criteria', 'scores', 'trainer', 'assessor', 'validator');

        return [
            'id' => $a->id, 'status' => $a->status, 'statusLabel' => CompetencyAssessment::STATUSES[$a->status] ?? $a->status, 'isLocked' => $a->is_locked,
            'percent' => $a->total_percent !== null ? (float) $a->total_percent : null, 'isCompetent' => $a->is_competent, 'threshold' => $a->rubric->competent_threshold,
            'summary' => $a->summary, 'recommendation' => $a->recommendation,
            'assessedAt' => $a->assessed_at?->toIso8601String(), 'assessedLabel' => $a->assessed_at ? IdDate::short($a->assessed_at) : null, 'assessedBy' => $a->trainer?->name ?? $a->assessor?->name,
            'validatedAt' => $a->validated_at?->toIso8601String(), 'validatedBy' => $a->validator?->name,
            'rubric' => ['id' => $a->rubric->id, 'name' => $a->rubric->name, 'description' => $a->rubric->description],
            'criteria' => $a->rubric->criteria->map(function ($c) use ($a) {
                $s = $a->scores->firstWhere('criterion_id', $c->id);
                $score = $s?->score !== null ? (float) $s->score : null;

                return [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'competency' => $c->competency, 'description' => $c->description,
                    'weight' => (float) $c->weight, 'maxScore' => (int) $c->max_score, 'levels' => $c->levels ?? [],
                    'score' => $score, 'levelLabel' => $c->levelLabel($score), 'note' => $s?->note,
                ];
            })->values(),
        ];
    }

    /** Bentuk JSON logbook (daftar/detail trainer & admin). @return array<string, mixed> */
    public static function logbook(TrainingLogbook $l): array
    {
        $l->loadMissing('entries', 'session', 'trainer', 'validator');

        return [
            'id' => $l->id, 'sessionId' => $l->session_id, 'sessionNo' => $l->session?->session_no,
            'date' => $l->date->toDateString(), 'dateLabel' => IdDate::long($l->date), 'topic' => $l->topic,
            'activities' => $l->activities, 'competencies' => $l->competencies ?? [], 'practice' => $l->practice, 'observations' => $l->observations, 'obstacles' => $l->obstacles, 'notes' => $l->notes,
            'documentation' => collect($l->documentation ?? [])->values()->map(fn ($d, $i) => ['index' => $i, 'name' => $d['name'] ?? basename($d['path'] ?? ''), 'mime' => $d['mime'] ?? null, 'size' => $d['size'] ?? null])->all(),
            'status' => $l->status, 'statusLabel' => TrainingLogbook::STATUSES[$l->status] ?? $l->status, 'isLocked' => $l->is_locked,
            'trainer' => $l->trainer?->name, 'validatedAt' => $l->validated_at?->toIso8601String(), 'validatedBy' => $l->validator?->name, 'updatedAt' => $l->updated_at?->toIso8601String(),
            'entries' => $l->entries->map(fn ($e) => ['enrollmentId' => $e->enrollment_id, 'practiceStatus' => $e->practice_status, 'practiceLabel' => LogbookEntry::PRACTICE[$e->practice_status ?? ''] ?? null, 'observation' => $e->observation])->values(),
        ];
    }
}
