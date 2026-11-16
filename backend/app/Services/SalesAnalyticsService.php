<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\TrainingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Analitik lanjutan Dashboard Eksekutif (PRD Roadmap 5 §8 - layer Executive, Sales Funnel, Customer):
 * KPI §8.1 per segmen (corporate vs self-funded), funnel penjualan corporate, dan cohort/repeat order + AOV.
 * Memakai filter yang sama dengan AnalyticsService; hasil di-memo per kombinasi filter selama satu request.
 */
class SalesAnalyticsService
{
    public const FUNNEL = [
        'lead' => 'Lead (request dibuat)',
        'request' => 'Request dikirim',
        'quotation' => 'Penawaran',
        'approval' => 'Disetujui',
        'paid' => 'Lunas',
        'completed' => 'Selesai',
        'repeat' => 'Repeat order',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private AnalyticsService $analytics, private KpiService $kpi) {}

    /** @return array<string, mixed> */
    public function dashboard(?array $filters = null): array
    {
        $f = $this->analytics->normalize($filters);
        $key = md5((string) json_encode($f));

        return $this->memo[$key] ??= $this->build($f);
    }

    /** @return array<string, mixed> */
    private function build(array $f): array
    {
        $scope = $this->kpi->scopeFromDashboard($f);
        $kpi = $this->kpi->bySegment($scope);

        return [
            'filters' => $f,
            'kpi' => $kpi,
            'kpi_table' => KpiService::table($kpi),
            'segments' => $this->segments($f, $scope, $kpi),
            'funnel' => $this->funnel($f, $scope),
            'cohorts' => $this->cohorts($f, $scope),
        ];
    }

    /** Corporate vs self-funded: enrollment, aktif, lulus, nilai order lunas, jumlah order. */
    private function segments(array $f, array $scope, array $kpi): array
    {
        $enrollments = Enrollment::query()->where('status', '!=', 'dropped')
            ->whereHas('schedule', fn (Builder $q) => $q->where('status', '!=', 'cancelled')->whereDate('start_date', '<=', $f['to'])->whereDate('end_date', '>=', $f['from']))
            ->when($scope['schedule_ids'] !== null, fn (Builder $q) => $q->whereIn('schedule_id', $scope['schedule_ids']))
            ->get(['id', 'type', 'status', 'member_id', 'sponsor_organization_id']);
        $out = [];
        foreach (['self', 'corporate'] as $seg) {
            $g = $enrollments->where('type', $seg);
            $aov = $kpi[$seg]['average_order_value'];
            $out[$seg] = [
                'label' => KpiService::SEGMENTS[$seg],
                'enrollments' => $g->count(),
                'participants' => $g->pluck('member_id')->filter()->unique()->count(),
                'active' => $g->whereIn('status', ['enrolled', 'attending'])->count(),
                'completed' => $g->where('status', 'completed')->count(),
                'organizations' => $seg === 'corporate' ? $g->pluck('sponsor_organization_id')->filter()->unique()->count() : null,
                'order_value' => $aov['numerator'],
                'orders' => $aov['denominator'],
            ];
        }
        $total = $out['self']['enrollments'] + $out['corporate']['enrollments'];
        foreach ($out as $seg => $row) {
            $out[$seg]['share'] = $total > 0 ? round($row['enrollments'] / $total * 100, 1) : null;
        }

        return $out;
    }

    /**
     * Funnel penjualan corporate (§8 Sales Funnel) dalam satuan Corporate Training Request yang DIBUAT dalam periode:
     * lead = dibuat (termasuk draf) → dikirim → penawaran → disetujui → lunas → selesai; repeat = request lunas dari
     * organisasi yang sudah pernah punya request lunas sebelumnya.
     */
    private function funnel(array $f, array $scope): array
    {
        $requests = TrainingRequest::query()->whereDate('created_at', '>=', $f['from'])->whereDate('created_at', '<=', $f['to'])
            ->when($scope['schedule_ids'] !== null, fn (Builder $q) => $q->where(fn (Builder $o) => $o->whereIn('schedule_id', $scope['schedule_ids'])
                ->orWhere(fn (Builder $n) => $n->whereNull('schedule_id')->whereIn('package_id', $scope['package_ids'] ?? []))))
            ->get(['id', 'organization_id', 'status', 'seats', 'submitted_at', 'quoted_at', 'approved_at', 'paid_at', 'created_at']);
        $firstPaid = TrainingRequest::query()->whereNotNull('paid_at')->whereIn('organization_id', $requests->pluck('organization_id')->unique()->values())
            ->selectRaw('organization_id, min(paid_at) as first_paid')->groupBy('organization_id')->pluck('first_paid', 'organization_id');

        $counts = [
            'lead' => $requests->count(),
            'request' => $requests->whereNotNull('submitted_at')->count(),
            'quotation' => $requests->whereNotNull('quoted_at')->count(),
            'approval' => $requests->whereNotNull('approved_at')->count(),
            'paid' => $requests->whereNotNull('paid_at')->count(),
            'completed' => $requests->where('status', 'completed')->count(),
            'repeat' => $requests->whereNotNull('paid_at')
                ->filter(fn ($r) => isset($firstPaid[$r->organization_id]) && $r->paid_at->gt(CarbonImmutable::parse($firstPaid[$r->organization_id])))->count(),
        ];
        $stages = [];
        $prev = null;
        foreach (self::FUNNEL as $k => $label) {
            $stages[] = [
                'key' => $k, 'label' => $label, 'count' => $counts[$k],
                'from_previous' => $k === 'repeat' ? null : ($prev !== null && $prev > 0 ? round($counts[$k] / $prev * 100, 1) : null),
                'from_lead' => $counts['lead'] > 0 ? round($counts[$k] / $counts['lead'] * 100, 1) : null,
            ];
            if ($k !== 'repeat') {
                $prev = $counts[$k];
            }
        }

        return [
            'stages' => $stages,
            'cancelled' => $requests->where('status', 'cancelled')->count(),
            'organizations' => $requests->pluck('organization_id')->unique()->count(),
            'seats_paid' => (int) $requests->whereNotNull('paid_at')->sum('seats'),
        ];
    }

    /**
     * Cohort pelanggan berdasarkan bulan order lunas PERTAMA (dalam periode): jumlah pelanggan, yang membeli lagi
     * (sampai akhir periode), repeat rate, rata-rata hari ke order kedua, dan AOV order cohort.
     * Pelanggan corporate = organisasi; pelanggan mandiri = member (atau email penagihan bila belum ada member).
     */
    private function cohorts(array $f, array $scope): array
    {
        $orders = Invoice::query()->where('status', '!=', 'cancelled')->whereNotNull('paid_at')->whereColumn('amount_paid', '>', 'amount_refunded')
            ->whereDate('paid_at', '<=', $f['to'])
            ->with(['registration:id,member_id,email', 'transactions' => fn ($q) => $q->where('status', 'paid'), 'refunds' => fn ($q) => $q->where('status', 'completed')])
            ->get(['id', 'organization_id', 'training_request_id', 'registration_id', 'bill_to_email', 'paid_at']);
        $rows = [];
        foreach (['corporate', 'self'] as $seg) {
            $mine = $orders->filter(fn (Invoice $i) => ($i->organization_id || $i->training_request_id) === ($seg === 'corporate'));
            $byCustomer = $mine->groupBy(fn (Invoice $i) => $seg === 'corporate'
                ? 'org:'.$i->organization_id
                : ($i->registration?->member_id ? 'm:'.$i->registration->member_id : 'e:'.mb_strtolower((string) ($i->bill_to_email ?? $i->registration?->email))))
                ->map(fn (Collection $g) => $g->sortBy('paid_at')->values());
            $cohorts = $byCustomer->filter(fn (Collection $g) => $g->first()->paid_at->toDateString() >= $f['from'])
                ->groupBy(fn (Collection $g) => $g->first()->paid_at->format('Y-m'), preserveKeys: true);
            foreach ($cohorts->sortKeys() as $month => $customers) {
                $repeaters = $customers->filter(fn (Collection $g) => $g->count() >= 2);
                $all = $customers->flatten(1);
                $rows[] = [
                    'key' => $seg.'-'.$month,
                    'segment' => $seg,
                    'segment_label' => KpiService::SEGMENTS[$seg],
                    'cohort' => $month,
                    'cohort_label' => AnalyticsService::monthLabel($month),
                    'customers' => $customers->count(),
                    'repeat' => $repeaters->count(),
                    'repeat_rate' => round($repeaters->count() / $customers->count() * 100, 1),
                    'avg_days_to_repeat' => $repeaters->isNotEmpty() ? round($repeaters->avg(fn (Collection $g) => $g[0]->paid_at->diffInDays($g[1]->paid_at)), 1) : null,
                    'orders' => $all->count(),
                    'aov' => $all->isNotEmpty() ? round($all->sum(fn (Invoice $i) => KpiService::orderValue($i)) / $all->count(), 2) : null,
                ];
            }
        }

        return $rows;
    }
}
