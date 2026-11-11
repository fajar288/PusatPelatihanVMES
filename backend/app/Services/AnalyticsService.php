<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingCategory;
use App\Models\TrainingLogbook;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executive dashboard & analitik manajemen (PRD §5.13, task Phase 5).
 *
 * Semua indikator dihitung dari satu set filter: periode (from/to), kategori, paket, kelas, trainer, dan status kelas.
 * - Kelas "dalam lingkup" = kelas yang cocok dengan filter non-periode dan rentang tanggalnya beririsan dengan periode.
 * - Pendaftaran & invoice dihitung berdasarkan tanggal dibuat dalam periode; pendapatan berdasarkan tanggal verifikasi.
 * - Piutang adalah posisi saat ini (invoice belum lunas yang dibuat sampai akhir periode).
 * Hasil di-memo per kombinasi filter selama satu request (widget dashboard memanggilnya berulang).
 */
class AnalyticsService
{
    public const FILTER_KEYS = ['from', 'to', 'category_id', 'package_id', 'schedule_id', 'trainer_id', 'status'];

    /** Fase kelas turunan (status + tanggal) untuk ringkasan kelas. */
    public const PHASES = [
        'upcoming' => 'Akan datang', 'running' => 'Berjalan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan', 'draft' => 'Draf',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Draf', 'registration_open' => 'Pendaftaran dibuka', 'registration_closed' => 'Pendaftaran ditutup',
        'confirmed' => 'Terkonfirmasi', 'ongoing' => 'Berlangsung', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
    ];

    /** Ambang default peserta berisiko bila paket tidak punya aturan kelulusan. */
    public const RISK_MIN_ATTENDANCE = 75;

    public const RISK_MIN_QUIZ = 60;

    public const RISK_INACTIVE_DAYS = 7;

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private readonly LmsService $lms) {}

    /**
     * Normalisasi filter dari form/URL. Periode default: 12 bulan terakhir (awal bulan s.d. hari ini).
     *
     * @param  array<string, mixed>|null  $input
     * @return array{from: string, to: string, category_id: ?int, package_id: ?int, schedule_id: ?int, trainer_id: ?int, status: ?string}
     */
    public function normalize(?array $input): array
    {
        $input ??= [];
        $date = function ($v, CarbonImmutable $fallback): CarbonImmutable {
            try {
                return filled($v) ? CarbonImmutable::parse((string) $v)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };
        $to = $date($input['to'] ?? null, CarbonImmutable::today());
        $from = $date($input['from'] ?? null, $to->subMonths(11)->startOfMonth());
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $id = fn ($v) => filled($v) && is_numeric($v) ? (int) $v : null;
        $status = $input['status'] ?? null;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'category_id' => $id($input['category_id'] ?? null),
            'package_id' => $id($input['package_id'] ?? null),
            'schedule_id' => $id($input['schedule_id'] ?? null),
            'trainer_id' => $id($input['trainer_id'] ?? null),
            'status' => in_array($status, TrainingSchedule::STATUSES, true) ? $status : null,
        ];
    }

    /** Seluruh data dashboard untuk satu set filter. @return array<string, mixed> */
    public function dashboard(?array $filters = null): array
    {
        $f = $this->normalize($filters);
        $key = md5((string) json_encode($f));

        return $this->memo[$key] ??= $this->build($f);
    }

    /** @return array<string, mixed> */
    private function build(array $f): array
    {
        $schedules = $this->schedules($f, true)->with('package:id,title,category_id,completion_rule')->get();
        $programIds = $this->schedules($f, false)->pluck('id'); // kelas sesuai filter tanpa batas periode
        $enrollments = Enrollment::query()->whereIn('schedule_id', $schedules->pluck('id'))->get();

        return [
            'filters' => $f,
            'period_label' => self::periodLabel($f),
            'generated_at' => now()->toIso8601String(),
            'participants' => $this->participants($f, $schedules, $programIds, $enrollments),
            'classes' => $this->classes($schedules),
            'finance' => $this->finance($f, $programIds),
            'learning' => $this->learning($schedules, $enrollments),
            'trainers' => $this->trainers($f, $schedules, $enrollments),
            'certificates' => $this->certificates($f, $programIds),
        ];
    }

    /** Kueri kelas sesuai filter; $withPeriod membatasi pada kelas yang beririsan dengan periode. */
    public function schedules(array $f, bool $withPeriod): Builder
    {
        return TrainingSchedule::query()
            ->when($f['schedule_id'], fn (Builder $q, $id) => $q->whereKey($id))
            ->when($f['package_id'], fn (Builder $q, $id) => $q->where('package_id', $id))
            ->when($f['category_id'], fn (Builder $q, $id) => $q->whereHas('package', fn (Builder $p) => $p->whereIn('category_id', self::categoryTree($id))))
            ->when($f['trainer_id'], fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                ->whereHas('trainers', fn (Builder $t) => $t->whereKey($id))
                ->orWhereHas('sessions', fn (Builder $s) => $s->where('trainer_id', $id))))
            ->when($f['status'], fn (Builder $q, $st) => $q->where('status', $st))
            ->when($withPeriod, fn (Builder $q) => $q->whereDate('start_date', '<=', $f['to'])->whereDate('end_date', '>=', $f['from']));
    }

    /** Kategori beserta turunannya (kategori hierarkis). @return array<int, int> */
    private static function categoryTree(int $id): array
    {
        $ids = [$id];
        $frontier = [$id];
        while ($frontier) {
            $frontier = TrainingCategory::query()->whereIn('parent_id', $frontier)->pluck('id')->diff($ids)->values()->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function participants(array $f, Collection $schedules, Collection $programIds, Collection $enrollments): array
    {
        $regs = Registration::query()->whereIn('schedule_id', $programIds)
            ->whereDate('created_at', '>=', $f['from'])->whereDate('created_at', '<=', $f['to'])
            ->get(['id', 'schedule_id', 'package_id', 'status', 'participants_count', 'created_at']);
        $byStatus = [];
        foreach (RegistrationStatus::cases() as $st) {
            $byStatus[$st->value] = ['label' => $st->label(), 'count' => $regs->where('status', $st)->count()];
        }
        $confirmed = $regs->where('status', RegistrationStatus::Confirmed)->count();

        $hasProgramFilter = $f['category_id'] || $f['package_id'] || $f['schedule_id'] || $f['trainer_id'] || $f['status'];
        $end = $f['to'].' 23:59:59';
        $newAlumni = $hasProgramFilter
            ? $enrollments->where('status', 'completed')->filter(fn ($e) => $e->completed_at && $e->completed_at->between($f['from'], $end))->pluck('member_id')->filter()->unique()->count()
            : Member::query()->where('status', 'alumni')->whereDate('alumni_since', '>=', $f['from'])->whereDate('alumni_since', '<=', $f['to'])->count();

        $packageOf = $schedules->pluck('package_id', 'id');
        $titles = TrainingPackage::query()->whereIn('id', $packageOf->unique()->values())->pluck('title', 'id');
        $popular = $enrollments->where('status', '!=', 'dropped')
            ->groupBy(fn ($e) => $packageOf[$e->schedule_id] ?? 0)
            ->map(fn ($g, $pid) => ['package_id' => (int) $pid, 'title' => $titles[$pid] ?? '—', 'participants' => $g->count(), 'classes' => $g->pluck('schedule_id')->unique()->count()])
            ->sortByDesc('participants')->take(8)->values()->all();

        return [
            'registrations' => $regs->count(),
            'registrations_participants' => (int) $regs->sum('participants_count'),
            'registrations_confirmed' => $confirmed,
            'conversion_rate' => $regs->count() > 0 ? round($confirmed / $regs->count() * 100, 1) : null,
            'registrations_by_status' => $byStatus,
            'registrations_by_month' => $this->months($f, $regs->groupBy(fn ($r) => $r->created_at->format('Y-m'))->map->count()),
            'enrolled' => $enrollments->where('status', '!=', 'dropped')->count(),
            'active' => $enrollments->whereIn('status', ['enrolled', 'attending'])->count(),
            'members_active' => Member::query()->where('status', 'active')->count(),
            'alumni_total' => Member::query()->where('status', 'alumni')->count(),
            'alumni_new' => $newAlumni,
            'popular_programs' => $popular,
        ];
    }

    /** Fase turunan satu kelas: draft / cancelled / completed / running / upcoming. */
    public static function phase(TrainingSchedule $s): string
    {
        $today = today();
        if ($s->status === 'cancelled') {
            return 'cancelled';
        }
        if ($s->status === 'draft') {
            return 'draft';
        }
        if ($s->status === 'completed' || $s->end_date->lt($today)) {
            return 'completed';
        }

        return $s->start_date->lte($today) || $s->status === 'ongoing' ? 'running' : 'upcoming';
    }

    /** @return array<string, mixed> */
    private function classes(Collection $schedules): array
    {
        $phases = $schedules->groupBy(fn (TrainingSchedule $s) => self::phase($s))->map->count();
        $withQuota = $schedules->filter(fn ($s) => $s->quota && ! in_array($s->status, ['cancelled', 'draft'], true));
        $quota = (int) $withQuota->sum('quota');
        $filled = (int) $withQuota->sum(fn ($s) => min((int) $s->registered_count, (int) $s->quota));

        return [
            'total' => $schedules->count(),
            'by_phase' => collect(self::PHASES)->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($phases[$k] ?? 0)])->all(),
            'by_status' => collect(self::STATUS_LABELS)->map(fn ($label, $k) => ['label' => $label, 'count' => $schedules->where('status', $k)->count()])->all(),
            'capacity' => [
                'quota' => $quota,
                'filled' => $filled,
                'remaining' => max(0, $quota - $filled),
                'utilization' => $quota > 0 ? round($filled / $quota * 100, 1) : null,
                'full_classes' => $withQuota->filter(fn ($s) => (int) $s->registered_count >= (int) $s->quota)->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function finance(array $f, Collection $programIds): array
    {
        $payments = Payment::query()
            ->whereHas('registration', fn (Builder $q) => $q->whereIn('schedule_id', $programIds))
            ->with('registration:id,schedule_id,package_id')
            ->get(['id', 'registration_id', 'amount', 'status', 'due_date', 'verified_at', 'created_at']);
        $inPeriod = fn ($d) => $d && $d->toDateString() >= $f['from'] && $d->toDateString() <= $f['to'];

        $invoices = $payments->filter(fn ($p) => $inPeriod($p->created_at));
        $verified = $payments->filter(fn ($p) => $p->status === 'verified' && $inPeriod($p->verified_at ?? $p->created_at));
        $open = $payments->filter(fn ($p) => in_array($p->status, ['unpaid', 'rejected'], true) && $p->created_at->toDateString() <= $f['to']);
        $overdue = $open->filter(fn ($p) => $p->due_date && $p->due_date->lt(today()));
        $submitted = $payments->filter(fn ($p) => $p->status === 'submitted');

        $schedulePackage = TrainingSchedule::query()->whereIn('id', $programIds)->pluck('package_id', 'id');
        $pkgOf = fn (Payment $p): int => (int) ($p->registration?->package_id ?? $schedulePackage[$p->registration?->schedule_id] ?? 0);
        $titles = TrainingPackage::query()->pluck('title', 'id');
        $byProgram = $verified->groupBy($pkgOf)
            ->map(fn ($g, $pid) => ['package_id' => (int) $pid, 'title' => $titles[$pid] ?? '—', 'amount' => (float) $g->sum('amount'), 'payments' => $g->count()])
            ->sortByDesc('amount')->take(8)->values()->all();
        $invoicedAmount = (float) $invoices->sum('amount');

        return [
            'revenue' => (float) $verified->sum('amount'),
            'invoices' => $invoices->count(),
            'invoiced_amount' => $invoicedAmount,
            'payments_verified' => $verified->count(),
            'pending_verification' => (float) $submitted->sum('amount'),
            'pending_count' => $submitted->count(),
            'outstanding' => (float) $open->sum('amount'),
            'outstanding_count' => $open->count(),
            'overdue' => (float) $overdue->sum('amount'),
            'overdue_count' => $overdue->count(),
            'collection_rate' => $invoicedAmount > 0 ? round((float) $invoices->where('status', 'verified')->sum('amount') / $invoicedAmount * 100, 1) : null,
            'revenue_by_month' => $this->months($f, $verified->groupBy(fn ($p) => ($p->verified_at ?? $p->created_at)->format('Y-m'))->map(fn ($g) => (float) $g->sum('amount'))),
            'revenue_by_program' => $byProgram,
        ];
    }

    /** @return array<string, mixed> */
    private function learning(Collection $schedules, Collection $enrollments): array
    {
        $ids = $enrollments->pluck('id');
        $held = ClassSession::query()->whereIn('schedule_id', $schedules->pluck('id'))->whereDate('date', '<=', today())
            ->select('schedule_id', DB::raw('count(*) as n'))->groupBy('schedule_id')->pluck('n', 'schedule_id');
        $present = Attendance::query()->whereIn('enrollment_id', $ids)->whereIn('status', ['present', 'late'])
            ->select('enrollment_id', DB::raw('count(*) as n'))->groupBy('enrollment_id')->pluck('n', 'enrollment_id');
        $attendance = $enrollments->where('status', '!=', 'dropped')->mapWithKeys(function ($e) use ($held, $present) {
            $h = (int) ($held[$e->schedule_id] ?? 0);

            return [$e->id => $h > 0 ? (int) round(min(1, ((int) ($present[$e->id] ?? 0)) / $h) * 100) : null];
        });
        $attendanceKnown = $attendance->filter(fn ($v) => $v !== null);

        // Progres LMS per peserta (hanya kelas yang punya enrollment).
        $lms = collect();
        foreach ($schedules->whereIn('id', $enrollments->pluck('schedule_id')->unique()) as $s) {
            foreach ($this->lms->classProgress($s) as $row) {
                $lms[$row['enrollment_id']] = $row;
            }
        }
        $withLessons = $lms->filter(fn ($r) => $r['lessons_total'] > 0);
        $quiz = $lms->pluck('quiz_avg')->filter(fn ($v) => $v !== null);

        $completed = $enrollments->where('status', 'completed')->count();
        $failed = $enrollments->where('status', 'failed')->count();
        $scores = $enrollments->whereNotNull('final_score')->map(fn ($e) => (float) $e->final_score);
        $assess = CompetencyAssessment::query()->whereIn('enrollment_id', $ids)->get(['enrollment_id', 'status', 'is_competent', 'total_percent']);
        $validated = $assess->where('status', 'validated');
        $validatedScored = $validated->whereNotNull('total_percent');

        return [
            'completed' => $completed,
            'failed' => $failed,
            'dropped' => $enrollments->where('status', 'dropped')->count(),
            'completion_rate' => $completed + $failed > 0 ? round($completed / ($completed + $failed) * 100, 1) : null,
            'avg_final_score' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
            'avg_attendance' => $attendanceKnown->isNotEmpty() ? round($attendanceKnown->avg(), 1) : null,
            'avg_lms_progress' => $withLessons->isNotEmpty() ? round($withLessons->avg('percent'), 1) : null,
            'avg_quiz' => $quiz->isNotEmpty() ? round($quiz->avg(), 1) : null,
            'assessments' => $assess->count(),
            'assessments_validated' => $validated->count(),
            'competent' => $validated->where('is_competent', true)->count(),
            'competent_rate' => $validated->isNotEmpty() ? round($validated->where('is_competent', true)->count() / $validated->count() * 100, 1) : null,
            'avg_competency' => $validatedScored->isNotEmpty() ? round($validatedScored->avg(fn ($a) => (float) $a->total_percent), 1) : null,
            'at_risk' => $this->atRisk($schedules, $enrollments, $attendance, $lms),
        ];
    }

    /**
     * Peserta berisiko tertinggal: peserta aktif di kelas yang sudah mulai dengan kehadiran di bawah ambang,
     * progres LMS tertinggal dari waktu kelas, rata-rata kuis rendah, atau tidak aktif di LMS.
     *
     * @return array<int, array<string, mixed>>
     */
    private function atRisk(Collection $schedules, Collection $enrollments, Collection $attendance, Collection $lms): array
    {
        $today = CarbonImmutable::today();
        $byId = $schedules->keyBy('id');
        $out = [];
        foreach ($enrollments->whereIn('status', ['enrolled', 'attending']) as $e) {
            $s = $byId[$e->schedule_id] ?? null;
            if (! $s || $s->start_date->gt($today) || in_array($s->status, ['cancelled', 'draft'], true)) {
                continue;
            }
            $minAtt = (int) ($s->package?->completionRule()['min_attendance_pct'] ?? 0) ?: self::RISK_MIN_ATTENDANCE;
            $reasons = [];
            $att = $attendance[$e->id] ?? null;
            if ($att !== null && $att < $minAtt) {
                $reasons[] = "Kehadiran {$att}% (< {$minAtt}%)";
            }
            $row = $lms[$e->id] ?? null;
            if ($row && $row['lessons_total'] > 0) {
                $days = max(1, (int) $s->start_date->diffInDays($s->end_date) + 1);
                $elapsed = (int) min(100, round(((int) $s->start_date->diffInDays($today) + 1) / $days * 100));
                $expected = (int) round($elapsed * 0.6);
                if ($row['percent'] < $expected) {
                    $reasons[] = "Progres materi {$row['percent']}% (target ≥ {$expected}%)";
                }
                if ($row['quiz_avg'] !== null && $row['quiz_avg'] < self::RISK_MIN_QUIZ) {
                    $reasons[] = "Rata-rata kuis {$row['quiz_avg']}";
                }
                $last = $row['last_seen_at'] ? CarbonImmutable::parse($row['last_seen_at']) : null;
                if ($s->end_date->gte($today) && (! $last || $last->lt($today->subDays(self::RISK_INACTIVE_DAYS)))) {
                    $reasons[] = $last ? 'Tidak aktif di LMS > '.self::RISK_INACTIVE_DAYS.' hari' : 'Belum membuka materi';
                }
            }
            if ($reasons) {
                $out[] = [
                    'enrollment_id' => $e->id, 'name' => $e->participant_name, 'email' => $e->participant_email,
                    'schedule_id' => $s->id, 'class' => $s->code, 'program' => $s->title ?? $s->package?->title, 'status' => $e->status,
                    'attendance' => $att, 'lms' => $row && $row['lessons_total'] > 0 ? $row['percent'] : null, 'quiz' => $row['quiz_avg'] ?? null,
                    'reasons' => $reasons, 'severity' => count($reasons),
                ];
            }
        }
        usort($out, fn ($a, $b) => [$b['severity'], $a['attendance'] ?? 101, $a['name']] <=> [$a['severity'], $b['attendance'] ?? 101, $b['name']]);

        return $out;
    }

    /** @return array<string, mixed> */
    private function trainers(array $f, Collection $schedules, Collection $enrollments): array
    {
        $scheduleIds = $schedules->pluck('id');
        $assign = DB::table('class_trainers')->whereIn('schedule_id', $scheduleIds)->get(['schedule_id', 'trainer_id', 'role']);
        $sessions = ClassSession::query()->whereIn('schedule_id', $scheduleIds)->whereNotNull('trainer_id')->get(['schedule_id', 'trainer_id', 'date']);
        $logbooks = TrainingLogbook::query()->whereIn('schedule_id', $scheduleIds)->get(['schedule_id', 'trainer_id', 'status']);
        $assess = CompetencyAssessment::query()->whereIn('enrollment_id', $enrollments->pluck('id'))->get(['enrollment_id', 'trainer_id', 'status', 'total_percent']);

        $trainerIds = $assign->pluck('trainer_id')->merge($sessions->pluck('trainer_id'))->map(fn ($id) => (int) $id)->unique()
            ->when($f['trainer_id'], fn ($c) => $c->filter(fn ($id) => $id === $f['trainer_id']));
        $trainers = Trainer::query()->whereIn('id', $trainerIds->values())->orderBy('name')->get(['id', 'name', 'title', 'is_active']);
        $today = today();
        $rows = $trainers->map(function (Trainer $t) use ($assign, $sessions, $logbooks, $assess, $enrollments, $today) {
            $classIds = $assign->where('trainer_id', $t->id)->pluck('schedule_id')->merge($sessions->where('trainer_id', $t->id)->pluck('schedule_id'))->unique();
            $en = $enrollments->whereIn('schedule_id', $classIds);
            $done = $en->where('status', 'completed')->count();
            $fail = $en->where('status', 'failed')->count();
            $mine = $sessions->where('trainer_id', $t->id);
            $myAssess = $assess->where('trainer_id', $t->id);
            $scored = $myAssess->where('status', 'validated')->whereNotNull('total_percent');

            return [
                'trainer_id' => $t->id, 'name' => $t->name, 'title' => $t->title,
                'classes' => $classIds->count(),
                'sessions_held' => $mine->filter(fn ($s) => $s->date->lte($today))->count(),
                'sessions_planned' => $mine->count(),
                'logbooks' => $logbooks->where('trainer_id', $t->id)->count(),
                'logbooks_validated' => $logbooks->where('trainer_id', $t->id)->where('status', 'validated')->count(),
                'participants' => $en->where('status', '!=', 'dropped')->count(),
                'assessments' => $myAssess->count(),
                'avg_competency' => $scored->isNotEmpty() ? round($scored->avg(fn ($a) => (float) $a->total_percent), 1) : null,
                'completion_rate' => $done + $fail > 0 ? round($done / ($done + $fail) * 100, 1) : null,
            ];
        })->sortByDesc('sessions_held')->values();

        return [
            'active' => Trainer::query()->where('is_active', true)->count(),
            'assessors' => Trainer::query()->where('is_active', true)->where('is_assessor', true)->count(),
            'assigned' => $trainers->count(),
            'assignments' => $assign->count(),
            'sessions_held' => $sessions->filter(fn ($s) => $s->date->lte($today))->count(),
            'logbooks' => $logbooks->count(),
            'logbooks_validated' => $logbooks->where('status', 'validated')->count(),
            'rows' => $rows->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function certificates(array $f, Collection $programIds): array
    {
        $certs = Certificate::query()->whereIn('schedule_id', $programIds)->get(['id', 'type', 'status', 'issued_on', 'created_at']);
        $issuedInPeriod = $certs->filter(fn ($c) => $c->issued_on && $c->issued_on->toDateString() >= $f['from'] && $c->issued_on->toDateString() <= $f['to']);

        return [
            'issued' => $issuedInPeriod->count(),
            'by_status' => collect(Certificate::STATUSES)->map(fn ($label, $k) => ['label' => $label, 'count' => $certs->where('status', $k)->count()])->all(),
            'by_type' => $issuedInPeriod->groupBy('type')->map->count()->all(),
            'awaiting' => $certs->whereIn('status', ['draft', 'pending_validation'])->count(),
            'issued_by_month' => $this->months($f, $issuedInPeriod->groupBy(fn ($c) => $c->issued_on->format('Y-m'))->map->count()),
        ];
    }

    /** Deret bulanan lengkap (bulan kosong = 0) dalam periode. @return array<string, float|int> */
    private function months(array $f, Collection $values): array
    {
        $out = [];
        $cur = CarbonImmutable::parse($f['from'])->startOfMonth();
        $end = CarbonImmutable::parse($f['to'])->startOfMonth();
        for ($i = 0; $cur->lte($end) && $i < 60; $i++, $cur = $cur->addMonth()) {
            $out[$cur->format('Y-m')] = $values[$cur->format('Y-m')] ?? 0;
        }

        return $out;
    }

    public static function monthLabel(string $ym): string
    {
        $m = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];

        return ($m[substr($ym, 5, 2)] ?? substr($ym, 5, 2)).' '.substr($ym, 2, 2);
    }

    public static function periodLabel(array $f): string
    {
        $fmt = fn (string $d) => (int) substr($d, 8, 2).' '.self::monthLabel(substr($d, 0, 7));

        return $fmt($f['from']).' – '.$fmt($f['to']);
    }

    /** Pilihan filter (dropdown). @return array<string, array<int|string, string>> */
    public function options(array $f = []): array
    {
        $category = filled($f['category_id'] ?? null) ? self::categoryTree((int) $f['category_id']) : null;

        return [
            'categories' => TrainingCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'packages' => TrainingPackage::query()
                ->when($category, fn (Builder $q, $ids) => $q->whereIn('category_id', $ids))
                ->orderBy('title')->pluck('title', 'id')->all(),
            'schedules' => TrainingSchedule::query()->with('package:id,title')
                ->when($f['package_id'] ?? null, fn (Builder $q, $id) => $q->where('package_id', $id))
                ->when($category, fn (Builder $q, $ids) => $q->whereHas('package', fn (Builder $p) => $p->whereIn('category_id', $ids)))
                ->orderByDesc('start_date')->limit(300)->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->code.' · '.($s->title ?? $s->package?->title)])->all(),
            'trainers' => Trainer::query()->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => self::STATUS_LABELS,
        ];
    }

    /**
     * Ringkasan indikator datar (Bagian, Indikator, Nilai) untuk ekspor.
     *
     * @return array<int, array{0: string, 1: string, 2: mixed}>
     */
    public function flatten(array $d): array
    {
        $p = $d['participants'];
        $c = $d['classes'];
        $fi = $d['finance'];
        $l = $d['learning'];
        $t = $d['trainers'];
        $ce = $d['certificates'];
        $rows = [
            ['Filter', 'Periode', $d['period_label']],
            ['Peserta', 'Pendaftaran (periode)', $p['registrations']],
            ['Peserta', 'Jumlah peserta pada pendaftaran', $p['registrations_participants']],
            ['Peserta', 'Pendaftaran terkonfirmasi', $p['registrations_confirmed']],
            ['Peserta', 'Konversi pendaftaran (%)', $p['conversion_rate']],
            ['Peserta', 'Peserta terdaftar di kelas', $p['enrolled']],
            ['Peserta', 'Peserta aktif', $p['active']],
            ['Peserta', 'Alumni (total)', $p['alumni_total']],
            ['Peserta', 'Alumni baru (periode)', $p['alumni_new']],
            ['Kelas', 'Jumlah kelas', $c['total']],
        ];
        foreach ($c['by_phase'] as $ph) {
            $rows[] = ['Kelas', 'Kelas '.mb_strtolower($ph['label']), $ph['count']];
        }
        array_push($rows,
            ['Kelas', 'Kapasitas (kuota)', $c['capacity']['quota']],
            ['Kelas', 'Kursi terisi', $c['capacity']['filled']],
            ['Kelas', 'Utilisasi kapasitas (%)', $c['capacity']['utilization']],
            ['Kelas', 'Kelas penuh', $c['capacity']['full_classes']],
            ['Keuangan', 'Pendapatan terverifikasi (Rp)', $fi['revenue']],
            ['Keuangan', 'Invoice diterbitkan', $fi['invoices']],
            ['Keuangan', 'Nilai invoice (Rp)', $fi['invoiced_amount']],
            ['Keuangan', 'Menunggu verifikasi (Rp)', $fi['pending_verification']],
            ['Keuangan', 'Piutang (Rp)', $fi['outstanding']],
            ['Keuangan', 'Piutang jatuh tempo (Rp)', $fi['overdue']],
            ['Keuangan', 'Tingkat penagihan (%)', $fi['collection_rate']],
            ['Pembelajaran', 'Lulus', $l['completed']],
            ['Pembelajaran', 'Tidak lulus', $l['failed']],
            ['Pembelajaran', 'Completion rate (%)', $l['completion_rate']],
            ['Pembelajaran', 'Rata-rata kehadiran (%)', $l['avg_attendance']],
            ['Pembelajaran', 'Rata-rata progres LMS (%)', $l['avg_lms_progress']],
            ['Pembelajaran', 'Rata-rata nilai kuis', $l['avg_quiz']],
            ['Pembelajaran', 'Rata-rata nilai akhir', $l['avg_final_score']],
            ['Pembelajaran', 'Penilaian kompetensi tervalidasi', $l['assessments_validated']],
            ['Pembelajaran', 'Kompeten (%)', $l['competent_rate']],
            ['Pembelajaran', 'Peserta berisiko', count($l['at_risk'])],
            ['Trainer', 'Trainer aktif', $t['active']],
            ['Trainer', 'Trainer bertugas (periode)', $t['assigned']],
            ['Trainer', 'Penugasan kelas', $t['assignments']],
            ['Trainer', 'Sesi mengajar terlaksana', $t['sessions_held']],
            ['Trainer', 'Logbook', $t['logbooks']],
            ['Sertifikat', 'Sertifikat terbit (periode)', $ce['issued']],
            ['Sertifikat', 'Menunggu proses (draf/validasi)', $ce['awaiting']],
        );
        foreach ($ce['by_status'] as $st) {
            $rows[] = ['Sertifikat', 'Status: '.$st['label'], $st['count']];
        }

        return $rows;
    }
}
