<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan portal trainer: ringkasan per kelas yang ditugaskan dan rincian per peserta untuk ekspor.
 * Tidak ada rumus baru: kehadiran & kelulusan dari KpiService (rumus KPI), progres LMS dari LmsService::classProgress,
 * kompetensi dari MonitoringService::classOverview, skor survei dari SurveyService::classReport dengan aturan
 * minimal pengisi yang sama dengan laporan survei trainer.
 */
class TrainerReportService
{
    public function __construct(
        private readonly KpiService $kpi,
        private readonly MonitoringService $monitoring,
        private readonly SurveyService $surveys,
    ) {}

    /**
     * Kelas yang ditugaskan ke trainer, dengan filter periode (kelas yang beririsan dengan from..to) dan status kelas.
     *
     * @param  array{from?: ?string, to?: ?string, status?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function classes(Trainer $trainer, array $filters = []): array
    {
        return $trainer->schedules()->with('package')
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('end_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('start_date', '<=', $to))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('training_schedules.status', $status))
            ->orderByDesc('start_date')->get()
            ->map(fn (TrainingSchedule $s) => $this->summary($s))->values()->all();
    }

    /** Ringkasan satu kelas. @return array<string, mixed> */
    public function summary(TrainingSchedule $s): array
    {
        $rows = collect(app(LmsService::class)->classProgress($s));
        $active = $rows->where('status', '!=', 'dropped');
        $statuses = $active->countBy('status');
        $scope = ['from' => $s->start_date->toDateString(), 'to' => $s->end_date->toDateString(), 'schedule_ids' => [$s->id]];
        $attendance = $this->kpi->attendanceRate($scope);
        // Completion Rate KPI: dihitung pada tanggal selesai kelas (null bila kelas belum berakhir/ditetapkan).
        $completion = $this->kpi->completionRate(['from' => $scope['to'], 'to' => $scope['to'], 'schedule_ids' => [$s->id]]);
        $scores = Enrollment::query()->where('schedule_id', $s->id)->where('status', '!=', 'dropped')->whereNotNull('final_score')->pluck('final_score');
        $hasLms = $active->contains(fn ($r) => $r['lessons_total'] > 0);
        $survey = $this->surveys->classReport($s);
        $surveyHidden = $survey['submitted'] < SurveyService::TRAINER_MIN_RESPONSES;

        return [
            'code' => $s->code,
            'title' => $s->title ?? $s->package?->title,
            'status' => $s->status,
            'startDate' => $s->start_date->toDateString(),
            'endDate' => $s->end_date->toDateString(),
            'dateLabel' => IdDate::long($s->start_date),
            'endLabel' => IdDate::long($s->end_date),
            'participants' => $active->count(),
            'dropped' => $rows->count() - $active->count(),
            'attendanceRate' => $attendance['value'],
            'averageFinalScore' => $scores->isEmpty() ? null : round((float) $scores->avg(), 1),
            'passed' => (int) ($statuses['completed'] ?? 0),
            'failed' => (int) ($statuses['failed'] ?? 0),
            'notEvaluated' => (int) (($statuses['enrolled'] ?? 0) + ($statuses['attending'] ?? 0)),
            // Ditampilkan setelah kelas berakhir agar tidak terbaca 100% saat sebagian peserta belum dievaluasi.
            'completionRate' => $s->status === 'completed' || $s->end_date->lt(today()) ? $completion['value'] : null,
            'lmsAverage' => $hasLms && $active->isNotEmpty() ? (int) round($active->avg('percent')) : null,
            'survey' => [
                'invited' => $survey['invited'],
                'submitted' => $survey['submitted'],
                'average' => $surveyHidden ? null : $survey['average'],
                'hidden' => $surveyHidden,
                'minResponses' => SurveyService::TRAINER_MIN_RESPONSES,
            ],
        ];
    }

    /**
     * Rincian per peserta satu kelas untuk ekspor (sumber sama dengan halaman Progres Peserta).
     *
     * @return array{headers: string[], rows: list<list<mixed>>}
     */
    public function participantDataset(TrainingSchedule $s): array
    {
        $finals = Enrollment::query()->where('schedule_id', $s->id)->pluck('final_score', 'id');
        $labels = ['enrolled' => 'Terdaftar', 'attending' => 'Mengikuti', 'completed' => 'Lulus', 'failed' => 'Tidak lulus', 'dropped' => 'Mundur'];
        $rows = $this->monitoring->classOverview($s)->map(fn (array $r) => [
            $r['name'], $r['email'], $labels[$r['status']] ?? $r['status'],
            $r['attendance_percent'], $r['lessons_total'] > 0 ? $r['percent'] : null, $r['lessons_completed'].'/'.$r['lessons_total'],
            $r['quiz_avg'], $r['assignments_graded'].'/'.$r['assignments_total'],
            $r['practice_completed'], $r['competency_percent'], $r['is_competent'] === null ? null : ($r['is_competent'] ? 'Kompeten' : 'Belum kompeten'),
            $finals[$r['enrollment_id']] !== null ? (float) $finals[$r['enrollment_id']] : null,
        ])->values()->all();

        return [
            'headers' => ['Nama', 'Email', 'Status', 'Kehadiran %', 'Progres LMS %', 'Materi selesai', 'Rata-rata kuis', 'Tugas dinilai', 'Praktik tuntas', 'Kompetensi %', 'Hasil kompetensi', 'Nilai akhir'],
            'rows' => $rows,
        ];
    }
}
