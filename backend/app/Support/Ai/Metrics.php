<?php

namespace App\Support\Ai;

/** Metrik evaluasi offline yang dipakai ai:evaluate (klasifikasi biner/multikelas dan regresi/prakiraan). */
final class Metrics
{
    /**
     * Klasifikasi biner. Kelas positif = true.
     *
     * @param  list<bool>  $predicted
     * @param  list<bool>  $actual
     * @return array{n:int,tp:int,fp:int,fn:int,tn:int,precision:?float,recall:?float,f1:?float,accuracy:?float}
     */
    public static function binary(array $predicted, array $actual): array
    {
        $tp = $fp = $fn = $tn = 0;
        foreach ($actual as $i => $a) {
            $p = (bool) ($predicted[$i] ?? false);
            match (true) {
                $p && $a => $tp++,
                $p && ! $a => $fp++,
                ! $p && $a => $fn++,
                default => $tn++,
            };
        }
        $n = count($actual);
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : null;
        $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0) ? 2 * $precision * $recall / ($precision + $recall) : null;

        return [
            'n' => $n, 'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn,
            'precision' => self::round($precision), 'recall' => self::round($recall), 'f1' => self::round($f1),
            'accuracy' => $n > 0 ? self::round(($tp + $tn) / $n) : null,
        ];
    }

    /**
     * Klasifikasi multikelas: akurasi dan macro-F1.
     *
     * @param  list<string>  $predicted
     * @param  list<string>  $actual
     * @return array{n:int,accuracy:?float,macro_f1:?float}
     */
    public static function multiclass(array $predicted, array $actual): array
    {
        $n = count($actual);
        if ($n === 0) {
            return ['n' => 0, 'accuracy' => null, 'macro_f1' => null];
        }
        $labels = array_values(array_unique(array_merge($actual, $predicted)));
        $f1s = [];
        foreach ($labels as $label) {
            $f1s[] = self::binary(
                array_map(fn ($p) => $p === $label, $predicted),
                array_map(fn ($a) => $a === $label, $actual),
            )['f1'] ?? 0.0;
        }
        $correct = count(array_filter($actual, fn ($a, $i) => ($predicted[$i] ?? null) === $a, ARRAY_FILTER_USE_BOTH));

        return ['n' => $n, 'accuracy' => self::round($correct / $n), 'macro_f1' => self::round(array_sum($f1s) / count($f1s))];
    }

    /**
     * Galat prakiraan. MAPE melewati nilai aktual 0 (tidak terdefinisi).
     *
     * @param  list<float|int>  $forecast
     * @param  list<float|int>  $actual
     * @return array{n:int,mae:?float,mape:?float}
     */
    public static function forecast(array $forecast, array $actual): array
    {
        $n = count($actual);
        if ($n === 0) {
            return ['n' => 0, 'mae' => null, 'mape' => null];
        }
        $abs = $pct = [];
        foreach ($actual as $i => $a) {
            $e = abs((float) ($forecast[$i] ?? 0) - (float) $a);
            $abs[] = $e;
            if ((float) $a != 0.0) {
                $pct[] = $e / abs((float) $a);
            }
        }

        return [
            'n' => $n,
            'mae' => self::round(array_sum($abs) / $n),
            'mape' => $pct ? self::round(array_sum($pct) / count($pct) * 100) : null,
        ];
    }

    /** Rasio aman (null bila penyebut 0). */
    public static function rate(int $part, int $total): ?float
    {
        return $total > 0 ? self::round($part / $total) : null;
    }

    private static function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 4);
    }
}
