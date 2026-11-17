<?php

namespace App\Support\Ai\Evaluators;

use App\Services\AiDemandForecastService;
use App\Support\Ai\Forecast\Forecaster;

/**
 * Prakiraan permintaan: MAE dan MAPE backtest satu langkah (bulan-bulan terakhir) untuk total, segmen, dan bidang,
 * dibanding baseline bulan yang sama tahun lalu. Baseline baru bisa diuji setelah riwayat minimal 12 bulan + bulan uji.
 */
class DemandForecastEvaluator implements Evaluator
{
    public function __construct(private readonly AiDemandForecastService $service) {}

    public function evaluate(): array
    {
        $m = $this->service->evaluate();
        $notes = [];
        if ($m['series'] === 0) {
            $notes[] = 'Riwayat peserta kurang dari '.Forecaster::MIN_HISTORY.' bulan lengkap, prakiraan belum bisa diuji.';
        }
        if ($m['beats_baseline'] === null) {
            $notes[] = 'Baseline bulan yang sama tahun lalu belum bisa diuji (butuh riwayat lebih dari 12 bulan).';
        }
        if ($m['beats_baseline'] === false) {
            $notes[] = 'Prakiraan kalah dari baseline bulan yang sama tahun lalu. Laporkan ke pemilik proyek.';
        }
        $rows = $m['rows'];
        unset($m['rows']);

        return [
            'metrics' => array_merge($m, ['beats_baseline' => match ($m['beats_baseline']) {
                true => 'ya',
                false => 'tidak',
                null => 'belum dapat dinilai',
            }, 'per_series' => $rows]),
            'baseline' => ['description' => Forecaster::METHODS[Forecaster::BASELINE], 'mae' => $m['baseline_mae']],
            'notes' => $notes,
        ];
    }
}
