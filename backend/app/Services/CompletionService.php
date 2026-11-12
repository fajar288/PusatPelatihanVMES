<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompetencyAssessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\TrainingPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aturan kelulusan per paket/kelas (PRD §5.10 "penerbitan didasarkan pada aturan kelulusan", §13 risiko "aturan kelulusan berbeda").
 * Mengevaluasi enrollment terhadap completion_rule paket + penilaian kompetensi, lalu menetapkan status completed/failed.
 */
class CompletionService
{
    public function __construct(private readonly MonitoringService $monitoring, private readonly LmsService $lms) {}

    /**
     * Evaluasi kelulusan: daftar pemeriksaan + kesimpulan.
     *
     * @return array{eligible: bool, checks: array<int, array{key: string, label: string, ok: bool, required: bool, detail: string}>, score: ?float, competency: ?array{percent: ?float, competent: ?bool, validated: bool}}
     */
    public function evaluate(Enrollment $e): array
    {
        $e->loadMissing('schedule.package', 'schedule.sessions');
        $rule = $e->schedule->package?->completionRule() ?? TrainingPackage::COMPLETION_DEFAULT;
        $ov = $this->monitoring->overview($e);
        $checks = [];

        // 1. Kehadiran
        $minAtt = (int) ($rule['min_attendance_pct'] ?? 0);
        $att = $ov['attendance'];
        if ($att['total'] === 0) {
            $checks[] = ['key' => 'attendance', 'label' => "Kehadiran minimal {$minAtt}%", 'ok' => true, 'required' => $minAtt > 0, 'detail' => 'Kelas belum memiliki sesi; kehadiran tidak diperiksa.'];
        } else {
            // kelulusan dinilai terhadap seluruh sesi kelas (bukan hanya yang sudah lewat)
            $pct = (int) round($att['present'] / $att['total'] * 100);
            $checks[] = ['key' => 'attendance', 'label' => "Kehadiran minimal {$minAtt}%", 'ok' => $minAtt === 0 || $pct >= $minAtt, 'required' => $minAtt > 0, 'detail' => "Hadir {$att['present']} dari {$att['total']} sesi ({$pct}%)."];
        }

        // 2. Nilai minimal: nilai akhir manual → rata-rata kuis/tugas → persentase kompetensi
        $score = $e->final_score !== null ? (float) $e->final_score : null;
        $source = 'nilai akhir';
        if ($score === null) {
            $parts = array_values(array_filter([$ov['scores']['quizAvg'], $ov['scores']['assignmentAvg']], fn ($v) => $v !== null));
            if ($parts !== []) {
                $score = round(array_sum($parts) / count($parts), 2);
                $source = 'rata-rata kuis/tugas';
            }
        }
        $assessment = CompetencyAssessment::query()->where('enrollment_id', $e->id)->first();
        if ($score === null && $assessment?->total_percent !== null) {
            $score = (float) $assessment->total_percent;
            $source = 'penilaian kompetensi';
        }
        $minScore = $rule['min_score'] !== null && $rule['min_score'] !== '' ? (float) $rule['min_score'] : null;
        if ($minScore !== null) {
            $checks[] = ['key' => 'score', 'label' => "Nilai minimal {$minScore}", 'ok' => $score !== null && $score >= $minScore, 'required' => true, 'detail' => $score === null ? 'Belum ada nilai.' : "Nilai {$score} ({$source})."];
        }

        // 3. Tugas wajib dikumpulkan
        if (! empty($rule['require_assignment'])) {
            $s = $ov['scores'];
            $checks[] = ['key' => 'assignment', 'label' => 'Semua tugas dikumpulkan', 'ok' => $s['assignmentsTotal'] === 0 || $s['assignmentsSubmitted'] >= $s['assignmentsTotal'], 'required' => true, 'detail' => "{$s['assignmentsSubmitted']} dari {$s['assignmentsTotal']} tugas dikumpulkan, {$s['assignmentsGraded']} dinilai."];
        }

        // 4. Post-test wajib lulus
        if (! empty($rule['require_posttest'])) {
            $courses = Course::query()->published()->forSchedule($e->schedule)->with('modules.lessons')->get();
            $posttests = $courses->flatMap(fn (Course $c) => $this->lms->publishedLessons($c))->filter(fn (Lesson $l) => $l->type === 'quiz' && ($l->config()['kind'] ?? 'quiz') === 'post_test');
            $passed = $posttests->filter(fn (Lesson $l) => $e->quizAttempts()->where('lesson_id', $l->id)->where('passed', true)->exists());
            $checks[] = ['key' => 'posttest', 'label' => 'Lulus post-test', 'ok' => $posttests->isNotEmpty() && $passed->count() === $posttests->count(), 'required' => true,
                'detail' => $posttests->isEmpty() ? 'Belum ada post-test pada course kelas ini.' : "{$passed->count()} dari {$posttests->count()} post-test lulus."];
        }

        // 5. Kompetensi — informasi untuk kelulusan; menjadi syarat saat menerbitkan sertifikat kompetensi (CertificateService::eligibility)
        $competency = $assessment ? ['percent' => $assessment->total_percent !== null ? (float) $assessment->total_percent : null, 'competent' => $assessment->is_competent, 'validated' => $assessment->isValidated()] : null;
        $checks[] = ['key' => 'competency', 'label' => 'Dinyatakan kompeten (penilaian tervalidasi)', 'ok' => (bool) ($competency['validated'] ?? false) && (bool) ($competency['competent'] ?? false), 'required' => false,
            'detail' => $competency === null ? 'Belum ada penilaian kompetensi.' : (($competency['validated'] ? 'Tervalidasi' : 'Belum divalidasi').', '.($competency['percent'] !== null ? $competency['percent'].'%' : '-').($competency['competent'] ? ', kompeten' : ($competency['competent'] === false ? ', belum kompeten' : '')).'.')];

        $eligible = collect($checks)->every(fn ($c) => ! $c['required'] || $c['ok']);

        return ['eligible' => $eligible, 'checks' => $checks, 'score' => $score, 'competency' => $competency];
    }

    /**
     * Tetapkan kelulusan: completed (lulus) atau failed. Tanpa $force, hasil mengikuti evaluasi.
     * Status member disinkronkan lewat event Enrollment::saved.
     */
    public function complete(Enrollment $e, User $actor, ?bool $pass = null, ?float $finalScore = null, ?string $note = null): Enrollment
    {
        $eval = $this->evaluate($e);
        $pass ??= $eval['eligible'];

        return DB::transaction(function () use ($e, $actor, $pass, $finalScore, $note, $eval) {
            $before = ['status' => $e->status, 'final_score' => $e->final_score, 'completed_at' => $e->completed_at?->toIso8601String()];
            $e->update([
                'status' => $pass ? 'completed' : 'failed',
                'completed_at' => $pass ? now() : null,
                'final_score' => $finalScore ?? ($e->final_score !== null ? $e->final_score : $eval['score']),
                'notes' => $note !== null && $note !== '' ? trim(($e->notes ? $e->notes."\n" : '').$note) : $e->notes,
            ]);
            AuditLog::record($pass ? 'enrollment.completed' : 'enrollment.failed', $e, $before, [
                'status' => $e->status, 'final_score' => $e->final_score, 'eligible' => $eval['eligible'], 'forced' => $pass !== $eval['eligible'],
                'checks' => collect($eval['checks'])->map(fn ($c) => $c['key'].':'.($c['ok'] ? 'ok' : 'fail'))->implode(','),
            ], $actor);
            $e->refresh();
            app(NotificationEvents::class)->trainingCompleted($e, $actor);

            return $e;
        });
    }
}
