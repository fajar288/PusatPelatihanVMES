<?php

namespace App\Support\Ai\EarlyWarning;

use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LogbookEntry;
use App\Models\TrainingSchedule;
use App\Services\AnalyticsService;
use App\Services\LmsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ekstraksi fitur early warning per enrollment. HANYA MEMBACA data (kehadiran, progres LMS, kuis, tugas, logbook);
 * dari LmsService hanya classProgress() yang dipakai, dan itu fungsi baca.
 *
 * Tanggal acuan: hari ini untuk peserta aktif, tanggal akhir kelas untuk kelas yang sudah selesai (data latih/evaluasi),
 * sehingga fitur kelas aktif dan kelas selesai sebanding. Setiap fitur adalah defisit 0..1 (lihat RiskModel).
 */
class FeatureExtractor
{
    /** Hari tanpa membuka materi yang dianggap defisit penuh. */
    public const INACTIVE_FULL_DAYS = 14;

    /** Defisit kehadiran baru berbobot penuh setelah sekian sesi tercatat (bukti dari 1 sesi saja belum meyakinkan). */
    public const ATTENDANCE_FULL_SESSIONS = 3;

    public function __construct(private readonly LmsService $lms) {}

    /**
     * Peserta aktif yang dinilai (aturan sama dengan Dashboard Eksekutif: enrolled/attending, kelas sudah mulai, tidak batal/draf).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function active(): Collection
    {
        $schedules = TrainingSchedule::query()->whereNotIn('status', ['cancelled', 'draft'])->whereDate('start_date', '<=', today())
            ->whereHas('enrollments', fn ($q) => $q->whereIn('status', ['enrolled', 'attending']))->with('package')->get();

        return $this->forSchedules($schedules, fn (Enrollment $e, TrainingSchedule $s) => AnalyticsService::riskEligible($e, $s), finished: false);
    }

    /**
     * Enrollment kelas yang sudah selesai dengan hasil lulus/tidak lulus (label: tidak lulus = berisiko).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function finished(): Collection
    {
        $schedules = TrainingSchedule::query()->whereHas('enrollments', fn ($q) => $q->whereIn('status', ['completed', 'failed']))->with('package')->get();

        return $this->forSchedules($schedules, fn (Enrollment $e) => in_array($e->status, ['completed', 'failed'], true), finished: true);
    }

    /**
     * @param  Collection<int, TrainingSchedule>  $schedules
     * @param  callable(Enrollment, TrainingSchedule): bool  $include
     * @return Collection<int, array<string, mixed>>
     */
    private function forSchedules(Collection $schedules, callable $include, bool $finished): Collection
    {
        $out = collect();
        $today = CarbonImmutable::today();
        foreach ($schedules as $s) {
            $enrollments = $s->enrollments()->get()->filter(fn (Enrollment $e) => $include($e, $s))->keyBy('id');
            if ($enrollments->isEmpty()) {
                continue;
            }
            $ref = $finished ? CarbonImmutable::parse($s->end_date)->min($today) : $today;
            $start = CarbonImmutable::parse($s->start_date);
            $days = max(1, (int) $start->diffInDays(CarbonImmutable::parse($s->end_date)) + 1);
            $elapsed = min(1.0, max(0.0, ((int) $start->diffInDays($ref) + 1) / $days));
            $minAtt = (int) ($s->package?->completionRule()['min_attendance_pct'] ?? 0) ?: AnalyticsService::RISK_MIN_ATTENDANCE;

            $progress = collect($this->lms->classProgress($s))->keyBy('enrollment_id');
            $assignments = $this->assignments($s);
            $submissions = $assignments->isEmpty() ? collect() : AssignmentSubmission::query()
                ->whereIn('enrollment_id', $enrollments->keys())->whereIn('lesson_id', $assignments->keys())
                ->get(['lesson_id', 'enrollment_id', 'status', 'score', 'submitted_at'])->groupBy('enrollment_id');
            $practice = LogbookEntry::query()->whereIn('enrollment_id', $enrollments->keys())
                ->whereHas('logbook', fn ($q) => $q->where('schedule_id', $s->id))
                ->get(['enrollment_id', 'practice_status'])->groupBy('enrollment_id');
            // Kehadiran hanya dari sesi yang absensinya SUDAH dicatat (sesi hari ini yang belum diabsen tidak dihitung
            // sebagai "tidak hadir"); tanpa sesi tercatat, fitur kehadiran tidak diukur.
            $recorded = ClassSession::query()->where('schedule_id', $s->id)->whereDate('date', '<=', $ref)
                ->whereHas('attendances')->pluck('id');
            $present = $recorded->isEmpty() ? collect() : Attendance::query()->whereIn('session_id', $recorded)
                ->whereIn('enrollment_id', $enrollments->keys())->whereIn('status', ['present', 'late'])
                ->selectRaw('enrollment_id, COUNT(*) AS n')->groupBy('enrollment_id')->pluck('n', 'enrollment_id');

            foreach ($enrollments as $e) {
                $p = $progress->get($e->id, []);
                $p['attendance_percent'] = $recorded->isEmpty() ? null : (int) round(min(1, ((int) ($present[$e->id] ?? 0)) / $recorded->count()) * 100);
                $p['attendance_sessions'] = $recorded->count();
                $raw = $this->raw($p, $submissions->get($e->id, collect()), $assignments, $practice->get($e->id, collect()), $ref, $elapsed, $days, $minAtt);
                $out->push([
                    'enrollment_id' => $e->id,
                    'schedule_id' => $s->id,
                    'class' => $s->code,
                    'sponsor_organization_id' => $e->type === Enrollment::TYPE_CORPORATE ? $e->sponsor_organization_id : null,
                    'status' => $e->status,
                    'outcome_failed' => $finished ? $e->status === 'failed' : null,
                    'end_date' => CarbonImmutable::parse($s->end_date)->toDateString(),
                    'raw' => $raw,
                    'x' => self::deficits($raw),
                ]);
            }
        }

        return $out->values();
    }

    /** Tugas (lesson assignment terbit) kelas ini beserta tenggat dan skor maksimal. @return Collection<int, array{due: ?CarbonImmutable, max: float}> */
    private function assignments(TrainingSchedule $s): Collection
    {
        return Course::query()->published()->forSchedule($s)->with('modules.lessons')->get()
            ->flatMap(fn (Course $c) => $c->modules->where('is_published', true)->flatMap(fn ($m) => $m->lessons->where('is_published', true)->where('type', 'assignment')))
            ->mapWithKeys(function ($l) {
                $cfg = $l->config();

                return [$l->id => ['due' => filled($cfg['due_at'] ?? null) ? CarbonImmutable::parse($cfg['due_at']) : null, 'max' => (float) ($cfg['max_score'] ?? 100) ?: 100.0]];
            });
    }

    /**
     * Nilai mentah yang dipakai (juga untuk kalimat alasan).
     *
     * @param  array<string, mixed>  $p  baris classProgress
     * @return array<string, mixed>
     */
    private function raw(array $p, Collection $subs, Collection $assignments, Collection $entries, CarbonImmutable $ref, float $elapsed, int $days, int $minAtt): array
    {
        $graded = $subs->where('status', 'graded')->filter(fn ($x) => $x->score !== null);
        $assignmentAvg = $graded->isEmpty() ? null : (int) round($graded->avg(fn ($x) => min(100, (float) $x->score / $assignments[$x->lesson_id]['max'] * 100)));
        $dueIds = $assignments->filter(fn ($a) => $a['due'] !== null && $a['due']->lte($ref->endOfDay()))->keys();
        $late = $dueIds->filter(function ($id) use ($subs, $assignments) {
            $sub = $subs->firstWhere('lesson_id', $id);

            return ! $sub || CarbonImmutable::parse($sub->submitted_at)->gt($assignments[$id]['due']);
        })->count();
        $lessonsTotal = (int) ($p['lessons_total'] ?? 0);
        $lastSeen = ! empty($p['last_seen_at']) ? CarbonImmutable::parse($p['last_seen_at']) : null;

        return [
            'attendance' => $p['attendance_percent'] ?? null,
            'attendance_sessions' => (int) ($p['attendance_sessions'] ?? 0),
            'min_attendance' => $minAtt,
            'lms_percent' => $lessonsTotal > 0 ? (int) ($p['percent'] ?? 0) : null,
            'lms_expected' => (int) round($elapsed * 100),
            'quiz_avg' => $p['quiz_avg'] ?? null,
            'assignment_avg' => $assignmentAvg,
            'assignments_due' => $dueIds->count(),
            'assignments_late' => $late,
            'practice_entries' => $entries->count(),
            'practice_not_done' => $entries->where('practice_status', 'not_done')->count(),
            'practice_partial' => $entries->where('practice_status', 'partial')->count(),
            'inactive_days' => $lessonsTotal > 0 ? ($lastSeen ? max(0, (int) $lastSeen->startOfDay()->diffInDays($ref)) : (int) round($elapsed * $days)) : null,
            'never_opened' => $lessonsTotal > 0 && $lastSeen === null,
        ];
    }

    /**
     * Defisit 0..1 per fitur (null = tidak bisa diukur).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, float|null>
     */
    public static function deficits(array $r): array
    {
        $clamp = fn (float $v) => round(max(0.0, min(1.0, $v)), 4);

        return [
            'attendance' => $r['attendance'] === null ? null
                : $clamp((1 - $r['attendance'] / 100) * min(1, ($r['attendance_sessions'] ?? self::ATTENDANCE_FULL_SESSIONS) / self::ATTENDANCE_FULL_SESSIONS)),
            'lms' => $r['lms_percent'] === null ? null : $clamp(($r['lms_expected'] - $r['lms_percent']) / 100),
            'quiz' => $r['quiz_avg'] === null ? null : $clamp(1 - $r['quiz_avg'] / 100),
            'assignment' => $r['assignment_avg'] === null ? null : $clamp(1 - $r['assignment_avg'] / 100),
            'late' => $r['assignments_due'] > 0 ? $clamp($r['assignments_late'] / $r['assignments_due']) : null,
            'practice' => $r['practice_entries'] > 0 ? $clamp(($r['practice_not_done'] + 0.5 * $r['practice_partial']) / $r['practice_entries']) : null,
            'inactive' => $r['inactive_days'] === null ? null : $clamp($r['inactive_days'] / self::INACTIVE_FULL_DAYS),
        ];
    }

    /**
     * Kalimat alasan untuk satu fitur (tanpa data pribadi).
     *
     * @param  array<string, mixed>  $r
     */
    public static function reason(string $feature, array $r): string
    {
        return match ($feature) {
            'attendance' => "Kehadiran {$r['attendance']}% dari {$r['attendance_sessions']} sesi tercatat (syarat lulus {$r['min_attendance']}%)",
            'lms' => "Progres materi {$r['lms_percent']}%, sesuai jadwal seharusnya {$r['lms_expected']}%",
            'quiz' => "Rata-rata kuis {$r['quiz_avg']}",
            'assignment' => "Rata-rata nilai tugas {$r['assignment_avg']}",
            'late' => "{$r['assignments_late']} dari {$r['assignments_due']} tugas terlambat atau belum dikumpulkan",
            'practice' => 'Praktik belum tuntas di '.($r['practice_not_done'] + $r['practice_partial'])." dari {$r['practice_entries']} pertemuan",
            'inactive' => $r['never_opened'] ? 'Belum pernah membuka materi' : "Tidak membuka materi selama {$r['inactive_days']} hari",
            default => $feature,
        };
    }
}
