<?php

namespace App\Support\Ai\Forecast;

use App\Support\Ai\Metrics;

/**
 * Prakiraan deret bulanan dengan metode statistik sederhana yang bisa dijelaskan (tanpa LLM, deterministik).
 *
 * Kandidat metode:
 *  - naive: nilai bulan terakhir;
 *  - moving_average: rata-rata 3 bulan terakhir;
 *  - seasonal_naive: nilai bulan yang sama tahun lalu (butuh minimal 12 bulan). Ini juga BASELINE pembanding.
 *
 * Metode dipilih per deret lewat backtest rolling-origin satu langkah ke depan pada bulan-bulan terakhir (holdout):
 * MAE terendah menang (seri: urutan METHODS). Rentang ketidakpastian ~80% = titik +/- kuantil 80% galat absolut
 * backtest x akar(langkah); bila galat backtest kurang dari MIN_ERRORS, pakai pendekatan Poisson 1,28 x akar(titik).
 */
final class Forecaster
{
    public const METHODS = [
        'moving_average' => 'Rata-rata 3 bulan terakhir',
        'naive' => 'Sama dengan bulan lalu',
        'seasonal_naive' => 'Sama dengan bulan yang sama tahun lalu',
    ];

    public const BASELINE = 'seasonal_naive';

    public const MAX_HOLDOUT = 6;

    public const MIN_ERRORS = 4;

    /** Minimal riwayat (bulan) agar deret diprakirakan: 3 bulan untuk metode + 1 bulan uji. */
    public const MIN_HISTORY = 4;

    private const MIN_FIT = ['naive' => 1, 'moving_average' => 3, 'seasonal_naive' => 12];

    /**
     * Prakiraan $step bulan setelah data terakhir (step >= 1). null bila riwayat belum cukup untuk metode itu.
     *
     * @param  list<int|float>  $history  lama ke baru
     */
    public static function predict(string $method, array $history, int $step = 1): ?float
    {
        $n = count($history);
        if ($n < self::MIN_FIT[$method]) {
            return null;
        }

        return match ($method) {
            'naive' => (float) $history[$n - 1],
            'moving_average' => array_sum(array_slice($history, -3)) / 3,
            // Bulan yang sama tahun lalu dari bulan target; lebih dari 12 langkah memakai tahun sebelumnya lagi.
            'seasonal_naive' => (float) $history[$n - 12 + (($step - 1) % 12)],
        };
    }

    /**
     * Backtest satu langkah: untuk tiap bulan holdout, prakirakan dari data sebelum bulan itu.
     *
     * @param  list<int|float>  $series
     * @return array{n:int,mae:?float,mape:?float,errors:list<float>}|null null bila metode tidak bisa diuji
     */
    public static function backtest(string $method, array $series, int $holdout): ?array
    {
        $n = count($series);
        $pred = $act = $errors = [];
        for ($t = $n - $holdout; $t < $n; $t++) {
            $p = $t >= 0 ? self::predict($method, array_slice($series, 0, $t), 1) : null;
            if ($p === null) {
                return null;
            }
            $pred[] = $p;
            $act[] = $series[$t];
            $errors[] = abs($series[$t] - $p);
        }
        if ($pred === []) {
            return null;
        }

        return Metrics::forecast($pred, $act) + ['errors' => $errors];
    }

    /**
     * Pilih metode terbaik lewat backtest, lalu prakirakan $horizon bulan ke depan dengan rentang ketidakpastian.
     *
     * @param  list<int|float>  $series
     * @return array<string, mixed>|null null bila riwayat < MIN_HISTORY
     */
    public static function forecast(array $series, int $horizon = 3): ?array
    {
        $n = count($series);
        if ($n < self::MIN_HISTORY) {
            return null;
        }
        // Holdout maksimal 6 bulan, tetapi sisakan minimal 3 bulan untuk metode rata-rata.
        $holdout = max(1, min(self::MAX_HOLDOUT, $n - 3));
        $tests = [];
        foreach (array_keys(self::METHODS) as $m) {
            if (($bt = self::backtest($m, $series, $holdout)) !== null) {
                $tests[$m] = $bt;
            }
        }
        $method = collect($tests)->sortBy(fn ($bt, $m) => [$bt['mae'], array_search($m, array_keys(self::METHODS), true)])->keys()->first();
        $chosen = $tests[$method];
        $baseline = $tests[self::BASELINE] ?? null;

        $errors = $chosen['errors'];
        sort($errors);
        $q80 = count($errors) >= self::MIN_ERRORS ? $errors[(int) ceil(0.8 * count($errors)) - 1] : null;

        $points = [];
        for ($k = 1; $k <= $horizon; $k++) {
            $p = max(0.0, (float) self::predict($method, $series, $k));
            $width = $q80 !== null ? $q80 * sqrt($k) : 1.28 * sqrt(max($p, 1.0)) * sqrt($k);
            $points[] = ['value' => round($p, 1), 'low' => round(max(0.0, $p - $width), 1), 'high' => round($p + $width, 1)];
        }

        return [
            'method' => $method,
            'method_label' => self::METHODS[$method],
            'history_months' => $n,
            'holdout' => $holdout,
            'interval' => $q80 !== null ? 'backtest' : 'poisson',
            'points' => $points,
            'backtest' => [
                'n' => $chosen['n'],
                'mae' => $chosen['mae'],
                'mape' => $chosen['mape'],
                'baseline_mae' => $baseline['mae'] ?? null,
                'baseline_mape' => $baseline['mape'] ?? null,
                // null = baseline (bulan yang sama tahun lalu) belum bisa diuji karena riwayat < 12 bulan + holdout.
                'beats_baseline' => $baseline === null ? null : $chosen['mae'] <= $baseline['mae'],
            ],
        ];
    }
}
