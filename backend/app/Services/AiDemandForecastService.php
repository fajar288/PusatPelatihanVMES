<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\Enrollment;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Support\Ai\DataReference;
use App\Support\Ai\Forecast\Forecaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prakiraan permintaan (use case demand_forecast): jumlah peserta terdaftar (enrollment) per bulan untuk total, segmen
 * (mandiri/corporate), bidang, dan program terpopuler, 3 bulan ke depan, dengan metode statistik (Forecaster, tanpa LLM).
 *
 * - Data: enrollments.enrolled_at per bulan KALENDER YANG SUDAH LENGKAP (bulan berjalan tidak dihitung), bulan kosong = 0.
 * - Hasil disimpan sebagai satu insight (review "staff": langsung tampil, hanya untuk staf di Dashboard Eksekutif);
 *   insight sebelumnya ditandai kedaluwarsa. Tidak ada data pribadi yang dipakai, hanya hitungan.
 * - Metrik: MAE dan MAPE backtest, dibanding baseline bulan yang sama tahun lalu (seasonal naive).
 * - Read-only terhadap data resmi; tidak mengubah kuota, jadwal, atau harga.
 */
class AiDemandForecastService
{
    public const USE_CASE = 'demand_forecast';

    public const HORIZON = 3;

    public const TOP_PACKAGES = 10;

    public function __construct(private readonly AiGovernance $governance, private readonly AiInsightService $insights, private readonly AiReadiness $readiness) {}

    /** @return array{available: bool, label: string, reason: ?string} */
    public function status(): array
    {
        $s = $this->governance->status(self::USE_CASE);

        return ['available' => $s['available'], 'label' => $s['available'] ? 'Aktif' : 'Belum tersedia', 'reason' => $s['reason_label']];
    }

    /**
     * Deret bulanan per lingkup, dari bulan enrollment pertama s.d. bulan lengkap terakhir sebelum $asOf.
     *
     * @return array{months: list<string>, series: list<array{scope:string,key:string,label:string,values:list<int>}>}
     */
    public function series(?Carbon $asOf = null): array
    {
        $end = ($asOf ?? now())->copy()->startOfMonth()->subMonth();
        $rows = DB::table('enrollments')
            ->join('training_schedules', 'training_schedules.id', '=', 'enrollments.schedule_id')
            ->join('training_packages', 'training_packages.id', '=', 'training_schedules.package_id')
            ->whereNotNull('enrollments.enrolled_at')->where('enrollments.enrolled_at', '<', $end->copy()->addMonth())
            ->get(['enrollments.enrolled_at', 'enrollments.type', 'training_packages.id as package_id', 'training_packages.category_id'])
            ->map(fn ($r) => ['month' => substr((string) $r->enrolled_at, 0, 7), 'type' => $r->type, 'package_id' => (int) $r->package_id, 'category_id' => $r->category_id ? (int) $r->category_id : null]);
        if ($rows->isEmpty()) {
            return ['months' => [], 'series' => []];
        }

        $months = [];
        for ($m = Carbon::createFromFormat('Y-m-d', $rows->min('month').'-01')->startOfMonth(); $m->lte($end); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $count = fn (Collection $g) => array_map(fn ($m) => $g->where('month', $m)->count(), $months);

        $series = [['scope' => 'total', 'key' => 'total', 'label' => 'Semua program', 'values' => $count($rows)]];
        foreach (Enrollment::TYPES as $type => $label) {
            $series[] = ['scope' => 'segment', 'key' => $type, 'label' => $label, 'values' => $count($rows->where('type', $type))];
        }
        $categories = TrainingCategory::query()->whereIn('id', $rows->pluck('category_id')->filter()->unique())->orderBy('name')->pluck('name', 'id');
        foreach ($categories as $id => $name) {
            $series[] = ['scope' => 'category', 'key' => (string) $id, 'label' => $name, 'values' => $count($rows->where('category_id', $id))];
        }
        // Program terpopuler 12 bulan terakhir (program lain tetap masuk total dan bidangnya).
        $recent = array_slice($months, -12);
        $top = $rows->whereIn('month', $recent)->countBy('package_id')->sortDesc()->take(self::TOP_PACKAGES)->keys();
        $titles = TrainingPackage::query()->whereIn('id', $top)->pluck('title', 'id');
        foreach ($top as $id) {
            $series[] = ['scope' => 'package', 'key' => (string) $id, 'label' => (string) ($titles[$id] ?? "Program #$id"), 'values' => $count($rows->where('package_id', $id))];
        }

        return ['months' => $months, 'series' => $series];
    }

    /** Hitung prakiraan dan simpan sebagai insight baru. null bila fitur mati atau riwayat belum cukup. */
    public function run(?Carbon $asOf = null): ?AiInsight
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return null;
        }
        $asOf ??= now();
        $data = $this->series($asOf);
        if (count($data['months']) < Forecaster::MIN_HISTORY) {
            return null;
        }
        $last = Carbon::createFromFormat('Y-m-d', end($data['months']).'-01');
        $target = array_map(fn ($k) => $last->copy()->addMonths($k)->format('Y-m'), range(1, self::HORIZON));

        $out = [];
        foreach ($data['series'] as $s) {
            $f = Forecaster::forecast($s['values'], self::HORIZON);
            if ($f === null) {
                continue;
            }
            $out[] = [
                'scope' => $s['scope'],
                'key' => $s['key'],
                'label' => $s['label'],
                'recent' => array_combine(array_slice($data['months'], -6), array_slice($s['values'], -6)),
                'total_12m' => array_sum(array_slice($s['values'], -12)),
            ] + $f;
        }
        $ready = $this->readiness->report(self::USE_CASE)['ready'];

        $insight = $this->insights->record(self::USE_CASE, [
            'kind' => 'forecast',
            'summary' => 'Prakiraan peserta '.implode(', ', $target).' untuk '.count($out).' lingkup',
            'generated_from' => end($data['months']),
            'months' => $target,
            'history_months' => count($data['months']),
            'data_ready' => $ready,
            'series' => $out,
        ], (new DataReference)->add('enrollments_monthly', ['months' => $data['months'], 'series' => $data['series']], 'monthly'),
            modelVersion: 'statistical:'.implode(',', array_keys(Forecaster::METHODS)),
            // Berlaku sampai akhir bulan berikutnya; perintah bulanan menggantinya lebih dulu.
            expiresAt: $asOf->copy()->startOfMonth()->addMonths(2));
        $this->insights->supersede(self::USE_CASE, null, $insight->id);

        return $insight;
    }

    /** Prakiraan terbaru yang masih berlaku. */
    public function latest(): ?AiInsight
    {
        return AiInsight::query()->visible()->where('use_case', self::USE_CASE)->latest('id')->first();
    }

    /**
     * Evaluasi backtest (dihitung ulang dari data saat ini): MAE & MAPE per lingkup total/segmen/bidang,
     * gabungan berbobot jumlah bulan uji, dibanding baseline bulan yang sama tahun lalu.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $data = $this->series();
        $rows = [];
        foreach ($data['series'] as $s) {
            if ($s['scope'] === 'package') {
                continue;
            }
            $f = Forecaster::forecast($s['values'], self::HORIZON);
            if ($f !== null) {
                $rows[] = ['scope' => $s['scope'], 'label' => $s['label'], 'method' => $f['method']] + $f['backtest'];
            }
        }
        $avg = fn (string $k, array $set) => ($n = array_sum(array_column($set, 'n'))) > 0
            ? round(array_sum(array_map(fn ($r) => $r[$k] * $r['n'], $set)) / $n, 2) : null;
        $withMape = array_values(array_filter($rows, fn ($r) => $r['mape'] !== null));
        $comparable = array_values(array_filter($rows, fn ($r) => $r['baseline_mae'] !== null));

        return [
            'history_months' => count($data['months']),
            'series' => count($rows),
            'mae' => $avg('mae', $rows),
            'mape' => $avg('mape', $withMape),
            'comparable_series' => count($comparable),
            'model_mae_vs_baseline' => $comparable === [] ? null : $avg('mae', $comparable),
            'baseline_mae' => $comparable === [] ? null : $avg('baseline_mae', $comparable),
            'beats_baseline' => $comparable === [] ? null : $avg('mae', $comparable) <= $avg('baseline_mae', $comparable),
            'rows' => $rows,
        ];
    }
}
