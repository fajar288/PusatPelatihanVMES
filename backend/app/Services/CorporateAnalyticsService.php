<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\CompetencyAssessment;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\RequestSeat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Analitik perusahaan (PRD Roadmap 5 §8.2, §20.15) untuk Corporate Portal. Semua angka hanya dari data yang boleh dilihat
 * pengguna perusahaan: enrollment yang disponsori organisasinya (+ batas unit), request & invoice organisasinya.
 * - Agregat menghitung semua enrollment bersponsor (termasuk peserta yang sudah keluar, sama dengan ringkasan 5B).
 * - Nama peserta berisiko hanya untuk peran view_progress dan hanya peserta yang detail progresnya boleh dibuka (exit_access).
 * - Biaya (cost per employee / per departemen) hanya untuk peran view_finance.
 */
class CorporateAnalyticsService
{
    public function __construct(
        private CorporateAccess $access,
        private CorporateReporting $reporting,
        private KpiService $kpi,
        private CompetencyGapService $competency,
    ) {}

    /** Normalisasi periode (default 12 bulan terakhir). @return array{from: string, to: string} */
    public static function period(?string $from, ?string $to): array
    {
        $parse = function (?string $v, CarbonImmutable $fallback): CarbonImmutable {
            try {
                return filled($v) ? CarbonImmutable::parse($v)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };
        $t = $parse($to, CarbonImmutable::today());
        $f = $parse($from, $t->subMonths(11)->startOfMonth());
        if ($f->gt($t)) {
            [$f, $t] = [$t, $f];
        }

        return ['from' => $f->toDateString(), 'to' => $t->toDateString()];
    }

    /** @return array<string, mixed> */
    public function build(CorporateUser $cu, string $from, string $to): array
    {
        $scope = ['from' => $from, 'to' => $to, 'segment' => 'corporate', 'corporate_user' => $cu];
        $kpi = $this->kpi->compute($scope);
        $risk = $kpi['at_risk_rate'];
        unset($kpi['at_risk_rate']['flagged']);

        // enrollment bersponsor (bukan dropped) di kelas yang beririsan dengan periode
        $enrollments = $this->access->sponsoredEnrollments($cu)->where('enrollments.status', '!=', 'dropped')
            ->whereHas('schedule', fn (Builder $q) => $q->where('status', '!=', 'cancelled')->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from))
            ->with(['schedule:id,code,title,package_id,start_date,end_date,start_time,end_time,status', 'schedule.package:id,title,slug,duration_hours', 'sponsorUnit:id,name,code'])
            ->get();
        $hours = $this->hours($enrollments, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'kpi' => collect($kpi)->map(fn ($v, $k) => $v + ['label' => KpiService::LABELS[$k], 'definition' => KpiService::DEFINITIONS[$k], 'display' => ($v['not_applicable'] ?? false) ? 'n/a' : KpiService::format($k, $v)])->all(),
            'utilization' => $this->utilization($cu, $from, $to),
            'participants' => $enrollments->pluck('member_id')->filter()->unique()->count(),
            'enrollments' => $enrollments->count(),
            'byBatch' => $this->byBatch($enrollments, $hours, $to),
            'byUnit' => $this->byUnit($enrollments, $hours, $to),
            'trainingHours' => [
                'total' => round(array_sum($hours['attended']), 1),
                'perEmployee' => ($n = $enrollments->pluck('member_id')->filter()->unique()->count()) > 0 ? round(array_sum($hours['attended']) / $n, 1) : null,
            ],
            'programs' => $this->programs($enrollments),
            'repeat' => $this->repeat($cu),
            'cost' => $cu->allows('view_finance') ? $this->cost($cu, $from, $to, $enrollments) : null,
            'risk' => [
                'count' => $risk['numerator'], 'active' => $risk['denominator'], 'rate' => $risk['value'],
                'rows' => $cu->allows('view_progress') ? $this->riskRows($cu, $risk['flagged']) : null,
            ],
            'competency' => $this->competency->matrix($cu)['summary'],
        ];
    }

    /**
     * Jam pelatihan per enrollment (docs/KPI.md "Training Hours"): durasi sesi yang dihadiri (hadir/terlambat) sampai akhir
     * periode. Durasi sesi = jam sesi → jam kelas → jam paket ÷ jumlah sesi → 0.
     *
     * @return array{attended: array<int, float>, sessionHours: array<int, float>}
     */
    private function hours(Collection $enrollments, string $to): array
    {
        $sessions = ClassSession::query()->whereIn('schedule_id', $enrollments->pluck('schedule_id')->unique()->values())->get(['id', 'schedule_id', 'date', 'start_time', 'end_time']);
        $perClass = $sessions->groupBy('schedule_id')->map->count();
        $schedules = $enrollments->pluck('schedule')->keyBy('id');
        $dur = function (?string $a, ?string $b): ?float {
            if (! $a || ! $b) {
                return null;
            }
            $m = (strtotime('1970-01-01 '.$b) - strtotime('1970-01-01 '.$a)) / 3600;

            return $m > 0 ? $m : null;
        };
        $sessionHours = [];
        foreach ($sessions as $s) {
            $sch = $schedules[$s->schedule_id] ?? null;
            $pkgHours = $sch?->package?->duration_hours;
            $sessionHours[$s->id] = (float) ($dur($s->start_time, $s->end_time) ?? $dur($sch?->start_time, $sch?->end_time)
                ?? ($pkgHours && ($perClass[$s->schedule_id] ?? 0) > 0 ? $pkgHours / $perClass[$s->schedule_id] : 0));
        }
        $attended = array_fill_keys($enrollments->pluck('id')->all(), 0.0);
        foreach ($enrollments->pluck('id')->chunk(500) as $ids) {
            Attendance::query()->whereIn('enrollment_id', $ids->values())->whereIn('status', ['present', 'late'])
                ->whereHas('session', fn (Builder $q) => $q->whereDate('date', '<=', $to))
                ->get(['enrollment_id', 'session_id'])
                ->each(function ($a) use (&$attended, $sessionHours): void {
                    $attended[$a->enrollment_id] += $sessionHours[$a->session_id] ?? 0;
                });
        }

        return ['attended' => $attended, 'sessionHours' => $sessionHours];
    }

    /** Kursi dibeli vs benar-benar digunakan (§20.15 Training Utilization) untuk request yang dikirim dalam periode. */
    private function utilization(CorporateUser $cu, string $from, string $to): array
    {
        $requests = $this->reporting->requestsQuery($cu)->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotNull('submitted_at')->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to)
            ->get(['id', 'seats', 'paid_at']);
        $paidIds = $requests->whereNotNull('paid_at')->pluck('id');
        $seats = RequestSeat::query()->whereIn('training_request_id', $paidIds)->get(['id', 'status', 'enrollment_id']);
        $enrollmentIds = $seats->pluck('enrollment_id')->filter()->values();
        $used = $enrollmentIds->isEmpty() ? 0 : Enrollment::query()->whereIn('id', $enrollmentIds)->where(fn (Builder $q) => $q
            ->where('status', 'completed')
            ->orWhereHas('attendances', fn (Builder $a) => $a->whereIn('status', ['present', 'late'])))->count();
        $bought = (int) $requests->whereNotNull('paid_at')->sum('seats');

        return [
            'requested' => (int) $requests->sum('seats'),
            'bought' => $bought,
            'assigned' => $seats->whereIn('status', ['assigned', 'enrolled'])->count(),
            'enrolled' => $seats->where('status', 'enrolled')->count(),
            'used' => $used,
            'rate' => $bought > 0 ? round($used / $bought * 100, 1) : null,
        ];
    }

    /** Kehadiran & completion per batch (kelas). */
    private function byBatch(Collection $enrollments, array $hours, string $to): array
    {
        $upto = min($to, CarbonImmutable::today()->toDateString());
        $held = ClassSession::query()->whereIn('schedule_id', $enrollments->pluck('schedule_id')->unique()->values())->whereDate('date', '<=', $upto)
            ->selectRaw('schedule_id, count(*) as n')->groupBy('schedule_id')->pluck('n', 'schedule_id');
        $present = $this->presentCounts($enrollments, $upto);

        return $enrollments->groupBy('schedule_id')->map(function (Collection $g, $sid) use ($held, $present, $hours) {
            $s = $g->first()->schedule;
            $scheduled = (int) ($held[$sid] ?? 0) * $g->count();
            $attended = (int) $g->sum(fn ($e) => (int) ($present[$e->id] ?? 0));
            $final = $g->whereIn('status', ['completed', 'failed'])->count();

            return [
                'code' => $s->code,
                'program' => $s->title ?? $s->package?->title,
                'startDate' => $s->start_date->toDateString(),
                'dates' => $s->start_date->format('d/m/Y').' - '.$s->end_date->format('d/m/Y'),
                'participants' => $g->count(),
                'attendanceRate' => $scheduled > 0 ? round($attended / $scheduled * 100, 1) : null,
                'completed' => $g->where('status', 'completed')->count(),
                'completionRate' => $final > 0 ? round($g->where('status', 'completed')->count() / $final * 100, 1) : null,
                'hours' => round((float) $g->sum(fn ($e) => $hours['attended'][$e->id] ?? 0), 1),
            ];
        })->sortByDesc('startDate')->values()->all();
    }

    /** Kehadiran, jam & peserta per departemen/unit sponsor. */
    private function byUnit(Collection $enrollments, array $hours, string $to): array
    {
        $upto = min($to, CarbonImmutable::today()->toDateString());
        $held = ClassSession::query()->whereIn('schedule_id', $enrollments->pluck('schedule_id')->unique()->values())->whereDate('date', '<=', $upto)
            ->selectRaw('schedule_id, count(*) as n')->groupBy('schedule_id')->pluck('n', 'schedule_id');
        $present = $this->presentCounts($enrollments, $upto);

        return $enrollments->groupBy(fn ($e) => $e->sponsor_unit_id ?? 0)->map(function (Collection $g) use ($held, $present, $hours) {
            $scheduled = (int) $g->sum(fn ($e) => (int) ($held[$e->schedule_id] ?? 0));
            $attended = (int) $g->sum(fn ($e) => (int) ($present[$e->id] ?? 0));
            $members = $g->pluck('member_id')->filter()->unique()->count();
            $total = (float) $g->sum(fn ($e) => $hours['attended'][$e->id] ?? 0);

            return [
                'unitId' => $g->first()->sponsor_unit_id,
                'unit' => $g->first()->sponsorUnit?->label() ?? 'Tanpa unit',
                'participants' => $members,
                'enrollments' => $g->count(),
                'attendanceRate' => $scheduled > 0 ? round($attended / $scheduled * 100, 1) : null,
                'completed' => $g->where('status', 'completed')->count(),
                'hours' => round($total, 1),
                'hoursPerEmployee' => $members > 0 ? round($total / $members, 1) : null,
            ];
        })->sortByDesc('hours')->values()->all();
    }

    /** @return Collection<int, int> enrollment_id → jumlah hadir/terlambat s.d. tanggal */
    private function presentCounts(Collection $enrollments, string $upto): Collection
    {
        $out = collect();
        foreach ($enrollments->pluck('id')->chunk(500) as $ids) {
            Attendance::query()->whereIn('enrollment_id', $ids->values())->whereIn('status', ['present', 'late'])
                ->whereHas('session', fn (Builder $q) => $q->whereDate('date', '<=', $upto))
                ->selectRaw('enrollment_id, count(*) as n')->groupBy('enrollment_id')->pluck('n', 'enrollment_id')
                ->each(function ($n, $id) use ($out): void {
                    $out[$id] = (int) $n;
                });
        }

        return $out;
    }

    /** Efektivitas program (§20.15 Program Effectiveness): completion, nilai akhir, kompeten per program. */
    private function programs(Collection $enrollments): array
    {
        $assess = CompetencyAssessment::query()->whereIn('enrollment_id', $enrollments->pluck('id'))->where('status', 'validated')
            ->get(['enrollment_id', 'is_competent', 'total_percent'])->keyBy('enrollment_id');

        return $enrollments->groupBy(fn ($e) => $e->schedule->package_id)->map(function (Collection $g) use ($assess) {
            $final = $g->whereIn('status', ['completed', 'failed'])->count();
            $scores = $g->whereNotNull('final_score')->map(fn ($e) => (float) $e->final_score);
            $a = $assess->only($g->pluck('id')->all());

            return [
                'program' => $g->first()->schedule->package?->title,
                'slug' => $g->first()->schedule->package?->slug,
                'participants' => $g->count(),
                'completed' => $g->where('status', 'completed')->count(),
                'completionRate' => $final > 0 ? round($g->where('status', 'completed')->count() / $final * 100, 1) : null,
                'avgScore' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
                'competentRate' => $a->isNotEmpty() ? round($a->where('is_competent', true)->count() / $a->count() * 100, 1) : null,
                'avgCompetency' => ($sc = $a->whereNotNull('total_percent'))->isNotEmpty() ? round($sc->avg(fn ($x) => (float) $x->total_percent), 1) : null,
            ];
        })->sortByDesc('participants')->values()->all();
    }

    /** Pembelian ulang (§20.15 Repeat Training): order lunas organisasi sepanjang waktu (dalam batas unit pengguna). */
    private function repeat(CorporateUser $cu): array
    {
        $orders = $this->reporting->invoicesQuery($cu)->whereNotNull('paid_at')->whereColumn('amount_paid', '>', 'amount_refunded')
            ->with(['trainingRequest:id,package_id', 'registration:id,schedule_id,package_id', 'registration.schedule:id,package_id'])
            ->orderBy('paid_at')->get(['id', 'paid_at', 'training_request_id', 'registration_id']);
        $programs = $orders->map(fn (Invoice $i) => $i->trainingRequest?->package_id ?? $i->registration?->package_id ?? $i->registration?->schedule?->package_id)->filter()->unique();

        return [
            'orders' => $orders->count(),
            'isRepeat' => $orders->count() >= 2,
            'programs' => $programs->count(),
            'firstOrder' => $orders->first()?->paid_at?->toDateString(),
            'lastOrder' => $orders->last()?->paid_at?->toDateString(),
            'daysToRepeat' => $orders->count() >= 2 ? (int) $orders[0]->paid_at->diffInDays($orders[1]->paid_at) : null,
        ];
    }

    /** Biaya training (§20.15 Cost per Employee, §8.2 cost by department) dari order lunas dalam periode. */
    private function cost(CorporateUser $cu, string $from, string $to, Collection $enrollments): array
    {
        $orders = $this->reporting->invoicesQuery($cu)->whereNotNull('paid_at')->whereColumn('amount_paid', '>', 'amount_refunded')
            ->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)
            ->with(['transactions' => fn ($q) => $q->where('status', 'paid'), 'refunds' => fn ($q) => $q->where('status', 'completed'), 'trainingRequest:id,unit_id', 'trainingRequest.unit:id,name,code'])
            ->get(['id', 'training_request_id', 'registration_id']);
        $total = (float) $orders->sum(fn (Invoice $i) => KpiService::orderValue($i));
        $members = $enrollments->pluck('member_id')->filter()->unique()->count();
        $byUnit = $orders->groupBy(fn (Invoice $i) => $i->trainingRequest?->unit_id ?? 0)->map(fn (Collection $g) => [
            'unit' => $g->first()->trainingRequest?->unit?->label() ?? 'Tanpa unit',
            'orders' => $g->count(),
            'amount' => round((float) $g->sum(fn (Invoice $i) => KpiService::orderValue($i)), 2),
        ])->sortByDesc('amount')->values()->all();

        return [
            'total' => round($total, 2),
            'orders' => $orders->count(),
            'perEmployee' => $members > 0 ? round($total / $members, 2) : null,
            'byUnit' => $byUnit,
        ];
    }

    /** Daftar peserta berisiko yang boleh dibuka per nama (tanpa email - §20.11). */
    private function riskRows(CorporateUser $cu, array $flagged): array
    {
        if ($flagged === []) {
            return [];
        }
        $visible = $this->reporting->progressQuery($cu)->whereIn('enrollments.id', array_column($flagged, 'enrollment_id'))->pluck('enrollments.id')->all();

        return collect($flagged)->filter(fn ($r) => in_array($r['enrollment_id'], $visible, true))->map(fn ($r) => [
            'enrollmentId' => $r['enrollment_id'], 'name' => $r['name'], 'class' => $r['class'], 'program' => $r['program'],
            'attendance' => $r['attendance'], 'lms' => $r['lms'], 'reasons' => $r['reasons'],
        ])->values()->all();
    }
}
