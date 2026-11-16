<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Registration;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * KPI PRD Roadmap 5 §8.1 — implementasi PERSIS definisi di docs/KPI.md (perubahan rumus wajib mengubah dokumen itu juga).
 *
 * Lingkup (scope) satu perhitungan:
 * - from/to      : periode (Y-m-d, inklusif).
 * - segment      : all | self (self-funded) | corporate (disponsori organisasi).
 * - schedule_ids : batas kelas dari filter program Dashboard Eksekutif (null = semua kelas).
 * - package_ids  : paket dari kelas terfilter (untuk request/invoice yang belum punya kelas; null = semua).
 * - corporate_user: bila diisi, semua populasi dibatasi data yang boleh dilihat pengguna perusahaan itu
 *                   (enrollment bersponsor + batas unit, invoice & request organisasi) — dipakai Corporate Portal & API.
 */
class KpiService
{
    public const SEGMENTS = ['all' => 'Semua', 'self' => 'Mandiri (self-funded)', 'corporate' => 'Corporate (disponsori)'];

    public const KEYS = ['completion_rate', 'attendance_rate', 'repeat_order_rate', 'average_order_value', 'training_conversion', 'at_risk_rate'];

    public const LABELS = [
        'completion_rate' => 'Completion Rate',
        'attendance_rate' => 'Attendance Rate',
        'repeat_order_rate' => 'Repeat Order Rate',
        'average_order_value' => 'Average Order Value',
        'training_conversion' => 'Training Conversion',
        'at_risk_rate' => 'At-Risk Rate',
    ];

    /** Definisi operasional ringkas (sama dengan docs/KPI.md) — ditampilkan di dashboard & API. */
    public const DEFINITIONS = [
        'completion_rate' => 'Enrollment lulus (completed) / enrollment yang eligible: bukan dropped, kelas tidak dibatalkan, tanggal selesai kelas dalam periode dan kelas sudah berakhir (atau hasil akhirnya sudah ditetapkan).',
        'attendance_rate' => 'Kehadiran (hadir/terlambat) / sesi terjadwal yang sudah berlangsung dalam periode, dijumlahkan untuk setiap enrollment bukan dropped di kelas yang tidak dibatalkan.',
        'repeat_order_rate' => 'Organisasi dengan ≥ 2 order lunas / organisasi dengan ≥ 1 order lunas, sampai akhir periode. Order = invoice tidak batal yang lunas dan nilai bersihnya > 0.',
        'average_order_value' => 'Nilai transaksi terverifikasi dikurangi refund selesai / jumlah order yang lunas dalam periode.',
        'training_conversion' => 'Enrollment dari order lunas / peluang terkualifikasi dalam periode (peserta pada pendaftaran yang tidak ditolak + kursi pada Corporate Training Request yang sudah dikirim).',
        'at_risk_rate' => 'Peserta aktif yang memenuhi ≥ 1 aturan risiko Dashboard Eksekutif / peserta aktif (enrolled/attending di kelas yang sudah mulai dan beririsan dengan periode).',
    ];

    public function __construct(private AnalyticsService $analytics, private CorporateReporting $reporting) {}

    /**
     * @param  array{from: string, to: string, segment?: string, schedule_ids?: ?array, package_ids?: ?array, corporate_user?: ?CorporateUser}  $scope
     * @return array<string, array<string, mixed>>
     */
    public function compute(array $scope): array
    {
        $s = $this->scope($scope);

        return [
            'completion_rate' => $this->completion($s),
            'attendance_rate' => $this->attendance($s),
            'repeat_order_rate' => $this->repeatOrder($s),
            'average_order_value' => $this->averageOrderValue($s),
            'training_conversion' => $this->conversion($s),
            'at_risk_rate' => $this->atRisk($s),
        ];
    }

    /** KPI untuk ketiga segmen sekaligus (Semua / Mandiri / Corporate). @return array<string, array<string, array<string, mixed>>> */
    public function bySegment(array $scope): array
    {
        $out = [];
        foreach (array_keys(self::SEGMENTS) as $seg) {
            $out[$seg] = $this->compute(['segment' => $seg] + $scope);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function scope(array $scope): array
    {
        $segment = $scope['segment'] ?? 'all';

        return [
            'from' => $scope['from'],
            'to' => $scope['to'],
            'segment' => array_key_exists($segment, self::SEGMENTS) ? $segment : 'all',
            'schedule_ids' => $scope['schedule_ids'] ?? null,
            'package_ids' => $scope['package_ids'] ?? null,
            'cu' => $scope['corporate_user'] ?? null,
            // tanggal acuan "sudah berlangsung": akhir periode atau hari ini, mana yang lebih awal
            'upto' => min($scope['to'], CarbonImmutable::today()->toDateString()),
        ];
    }

    private static function rate(int|float $num, int|float $den, int $precision = 1): ?float
    {
        return $den > 0 ? round($num / $den * 100, $precision) : null;
    }

    /** Enrollment dalam scope: batas pengguna perusahaan, segmen, dan filter kelas. */
    private function enrollments(array $s): Builder
    {
        $q = $s['cu'] ? Enrollment::query()->visibleToCorporateUser($s['cu']) : Enrollment::query();

        return $q->when($s['segment'] === 'self', fn (Builder $w) => $w->where('enrollments.type', Enrollment::TYPE_SELF))
            ->when($s['segment'] === 'corporate', fn (Builder $w) => $w->where('enrollments.type', Enrollment::TYPE_CORPORATE))
            ->when($s['schedule_ids'] !== null, fn (Builder $w) => $w->whereIn('enrollments.schedule_id', $s['schedule_ids']));
    }

    /** Hanya Completion Rate (dipakai indikator pembelajaran Dashboard Eksekutif & ringkasan perusahaan). */
    public function completionRate(array $scope): array
    {
        return $this->completion($this->scope($scope));
    }

    /** Hanya Attendance Rate. */
    public function attendanceRate(array $scope): array
    {
        return $this->attendance($this->scope($scope));
    }

    /** §8.1 Completion Rate = completed enrollments / enrollments eligible for completion. */
    private function completion(array $s): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $rows = $this->enrollments($s)->where('enrollments.status', '!=', 'dropped')
            ->whereHas('schedule', fn (Builder $q) => $q->where('status', '!=', 'cancelled')
                ->whereDate('end_date', '>=', $s['from'])->whereDate('end_date', '<=', $s['to']))
            ->where(fn (Builder $w) => $w->whereIn('enrollments.status', ['completed', 'failed'])
                ->orWhereHas('schedule', fn (Builder $q) => $q->where(fn (Builder $e) => $e->whereDate('end_date', '<', $today)->orWhere('status', 'completed'))))
            ->pluck('enrollments.status');
        $done = $rows->filter(fn ($st) => $st === 'completed')->count();

        return [
            'value' => self::rate($done, $rows->count()),
            'numerator' => $done,
            'denominator' => $rows->count(),
            'failed' => $rows->filter(fn ($st) => $st === 'failed')->count(),
            'pending_evaluation' => $rows->filter(fn ($st) => in_array($st, ['enrolled', 'attending'], true))->count(),
        ];
    }

    /** §8.1 Attendance Rate = attended learning units / scheduled learning units. */
    private function attendance(array $s): array
    {
        $enrollments = $this->enrollments($s)->where('enrollments.status', '!=', 'dropped')
            ->whereHas('schedule', fn (Builder $q) => $q->where('status', '!=', 'cancelled'))
            ->get(['enrollments.id', 'enrollments.schedule_id']);
        if ($enrollments->isEmpty() || $s['upto'] < $s['from']) {
            return ['value' => null, 'numerator' => 0, 'denominator' => 0];
        }
        $window = fn (Builder $q) => $q->whereDate('date', '>=', $s['from'])->whereDate('date', '<=', $s['upto']);
        $perClass = ClassSession::query()->whereIn('schedule_id', $enrollments->pluck('schedule_id')->unique()->values())->where($window)
            ->selectRaw('schedule_id, count(*) as n')->groupBy('schedule_id')->pluck('n', 'schedule_id');
        $scheduled = (int) $enrollments->sum(fn ($e) => (int) ($perClass[$e->schedule_id] ?? 0));
        $attended = 0;
        foreach ($enrollments->pluck('id')->chunk(500) as $ids) {
            $attended += Attendance::query()->whereIn('enrollment_id', $ids->values())->whereIn('status', ['present', 'late'])
                ->whereHas('session', $window)->count();
        }

        return ['value' => self::rate($attended, $scheduled), 'numerator' => $attended, 'denominator' => $scheduled];
    }

    /**
     * Order lunas yang diakui (docs/KPI.md "Order"): invoice tidak batal, sudah lunas (paid_at), nilai bersih > 0.
     * Segmen corporate = invoice organisasi (training request / pendaftaran kolektif yang ditautkan); self = sisanya.
     */
    private function orders(array $s): Builder
    {
        $q = $s['cu'] ? $this->reporting->invoicesQuery($s['cu']) : Invoice::query()->where('status', '!=', 'cancelled');

        return $q->whereNotNull('paid_at')->whereColumn('amount_paid', '>', 'amount_refunded')
            ->when($s['segment'] === 'corporate', fn (Builder $w) => $w->where(fn (Builder $o) => $o->whereNotNull('organization_id')->orWhereNotNull('training_request_id')))
            ->when($s['segment'] === 'self', fn (Builder $w) => $w->whereNull('organization_id')->whereNull('training_request_id'))
            ->when($s['schedule_ids'] !== null, fn (Builder $w) => $w->where(fn (Builder $o) => $o
                ->whereHas('registration', fn (Builder $r) => $r->whereIn('schedule_id', $s['schedule_ids']))
                ->orWhereHas('trainingRequest', fn (Builder $r) => $r->whereIn('schedule_id', $s['schedule_ids'])
                    ->orWhere(fn (Builder $n) => $n->whereNull('schedule_id')->whereIn('package_id', $s['package_ids'] ?? [])))));
    }

    /** §8.1 Repeat Order Rate = corporate accounts with repeat purchase / corporate accounts eligible. */
    private function repeatOrder(array $s): array
    {
        if ($s['segment'] === 'self') {
            return ['value' => null, 'numerator' => 0, 'denominator' => 0, 'not_applicable' => true];
        }
        $counts = $this->orders(['segment' => 'corporate'] + $s)->whereNotNull('organization_id')->whereDate('paid_at', '<=', $s['to'])
            ->selectRaw('organization_id, count(*) as n')->groupBy('organization_id')->pluck('n', 'organization_id');
        $repeat = $counts->filter(fn ($n) => (int) $n >= 2)->count();

        return ['value' => self::rate($repeat, $counts->count()), 'numerator' => $repeat, 'denominator' => $counts->count()];
    }

    /** §8.1 Average Order Value = recognized transaction value / number of orders. */
    private function averageOrderValue(array $s): array
    {
        $orders = $this->orders($s)->whereDate('paid_at', '>=', $s['from'])->whereDate('paid_at', '<=', $s['to'])
            ->with(['transactions' => fn ($q) => $q->where('status', 'paid'), 'refunds' => fn ($q) => $q->where('status', 'completed')])
            ->get(['id', 'organization_id', 'training_request_id']);
        $value = (float) $orders->sum(fn (Invoice $i) => self::orderValue($i));

        return [
            'value' => $orders->isNotEmpty() ? round($value / $orders->count(), 2) : null,
            'numerator' => round($value, 2),
            'denominator' => $orders->count(),
        ];
    }

    /** Nilai diakui satu order: transaksi terverifikasi (nominal diterima) − refund selesai. */
    public static function orderValue(Invoice $i): float
    {
        $received = (float) $i->transactions->where('status', 'paid')->sum(fn ($t) => (float) ($t->amount_received ?? $t->amount));

        return $received - (float) $i->refunds->where('status', 'completed')->sum('amount');
    }

    /** §8.1 Training Conversion = paid enrollments / qualified opportunities. */
    private function conversion(array $s): array
    {
        $regs = $this->opportunityRegistrations($s)->get(['id', 'participants_count', 'total', 'status']);
        $requests = $this->opportunityRequests($s)->get(['id', 'seats', 'paid_at']);

        $paidRegIds = $regs->isEmpty() ? collect() : Invoice::query()->whereIn('registration_id', $regs->pluck('id'))
            ->where('status', '!=', 'cancelled')->whereNotNull('paid_at')->distinct()->pluck('registration_id');
        // pendaftaran tanpa biaya (total 0) dianggap lunas saat terkonfirmasi
        $free = $regs->filter(fn ($r) => $r->status?->value === 'confirmed' && $r->total !== null && (float) $r->total == 0.0)->pluck('id');
        $paidRegIds = $paidRegIds->merge($free)->unique()->values();
        $paidRequestIds = $requests->whereNotNull('paid_at')->pluck('id');

        $paid = 0;
        if ($paidRegIds->isNotEmpty()) {
            $paid += $this->enrollments(['schedule_ids' => null] + $s)->whereIn('registration_id', $paidRegIds)->count();
        }
        if ($paidRequestIds->isNotEmpty()) {
            $paid += $this->enrollments(['schedule_ids' => null] + $s)->whereIn('enrollments.id', RequestSeat::query()
                ->whereIn('training_request_id', $paidRequestIds)->whereNotNull('enrollment_id')->select('enrollment_id'))->count();
        }
        $opportunities = (int) $regs->sum('participants_count') + (int) $requests->sum('seats');

        return [
            'value' => self::rate($paid, $opportunities),
            'numerator' => $paid,
            'denominator' => $opportunities,
            'registrations' => $regs->count(),
            'requests' => $requests->count(),
        ];
    }

    private function opportunityRegistrations(array $s): Builder
    {
        $cu = $s['cu'];
        $q = Registration::query()->where('status', '!=', 'rejected')
            ->whereDate('created_at', '>=', $s['from'])->whereDate('created_at', '<=', $s['to'])
            ->when($s['schedule_ids'] !== null, fn (Builder $w) => $w->whereIn('schedule_id', $s['schedule_ids']))
            ->when($s['segment'] === 'self', fn (Builder $w) => $w->where('type', 'individual')->whereNull('organization_id'))
            ->when($s['segment'] === 'corporate', fn (Builder $w) => $w->where(fn (Builder $o) => $o->where('type', 'corporate')->orWhereNotNull('organization_id')));
        if ($cu) {
            // pendaftaran kolektif tertaut organisasi tidak punya unit → hanya untuk pengguna tanpa batas unit (sama dengan invoicesQuery)
            $q->where('organization_id', $cu->unitScope() === null ? $cu->organization_id : 0);
        }

        return $q;
    }

    private function opportunityRequests(array $s): Builder
    {
        if ($s['segment'] === 'self') {
            return TrainingRequest::query()->whereRaw('1 = 0');
        }
        $q = $s['cu'] ? $this->reporting->requestsQuery($s['cu']) : TrainingRequest::query();

        return $q->whereNotNull('submitted_at')->whereDate('submitted_at', '>=', $s['from'])->whereDate('submitted_at', '<=', $s['to'])
            ->when($s['schedule_ids'] !== null, fn (Builder $w) => $w->where(fn (Builder $o) => $o->whereIn('schedule_id', $s['schedule_ids'])
                ->orWhere(fn (Builder $n) => $n->whereNull('schedule_id')->whereIn('package_id', $s['package_ids'] ?? []))));
    }

    /** §8.1 At-Risk Rate = active participants flagged by defined risk rules / active participants. */
    private function atRisk(array $s): array
    {
        $enrollments = $this->enrollments($s)->whereIn('enrollments.status', ['enrolled', 'attending'])
            ->whereHas('schedule', fn (Builder $q) => $q->whereDate('start_date', '<=', $s['to'])->whereDate('end_date', '>=', $s['from']))
            ->get();
        $r = $this->analytics->riskFor($enrollments);

        return [
            'value' => self::rate(count($r['flagged']), $r['active']),
            'numerator' => count($r['flagged']),
            'denominator' => $r['active'],
            'flagged' => $r['flagged'],
        ];
    }

    /** Teks nilai KPI siap tampil. */
    public static function format(string $key, array $kpi): string
    {
        if ($kpi['value'] === null) {
            return '—';
        }
        if ($key === 'average_order_value') {
            return 'Rp '.number_format((float) $kpi['value'], 0, ',', '.');
        }

        return rtrim(rtrim(number_format((float) $kpi['value'], 1, ',', '.'), '0'), ',').'%';
    }

    /** Baris ekspor/tabel: [kpi, segmen => teks nilai (n/d)]. @return array<int, array<string, mixed>> */
    public static function table(array $bySegment): array
    {
        $rows = [];
        foreach (self::KEYS as $k) {
            $row = ['key' => $k, 'label' => self::LABELS[$k], 'definition' => self::DEFINITIONS[$k]];
            foreach ($bySegment as $seg => $kpis) {
                $v = $kpis[$k];
                $row[$seg] = ($v['not_applicable'] ?? false) ? 'n/a' : self::format($k, $v);
                $row[$seg.'_detail'] = ($v['not_applicable'] ?? false) ? null : ($k === 'average_order_value'
                    ? $v['denominator'].' order'
                    : $v['numerator'].' / '.$v['denominator']);
                $row[$seg.'_value'] = $v['value'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Filter Dashboard Eksekutif → scope KPI. */
    public function scopeFromDashboard(array $f): array
    {
        $hasProgram = $f['category_id'] || $f['package_id'] || $f['schedule_id'] || $f['trainer_id'] || $f['status'];
        $ids = $hasProgram ? $this->analytics->schedules($f, false)->pluck('id')->all() : null;

        return [
            'from' => $f['from'], 'to' => $f['to'],
            'schedule_ids' => $ids,
            'package_ids' => $ids === null ? null : TrainingSchedule::query()->whereIn('id', $ids)->distinct()->pluck('package_id')->all(),
        ];
    }

    /** Ringkas untuk koleksi: [key => value]. @return array<string, float|null> */
    public static function values(array $kpis): array
    {
        return collect($kpis)->map(fn ($k) => $k['value'])->all();
    }
}
