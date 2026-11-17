<?php

namespace App\Support\Ai\EarlyWarning;

/**
 * Model risiko early warning: regresi logistik sederhana yang transparan.
 *
 *   peluang tidak lulus = sigmoid(intercept + jumlah(bobot_i x defisit_i))
 *
 * Setiap fitur adalah "defisit" 0..1 (0 = baik, 1 = paling buruk); fitur yang tidak bisa diukur (null) tidak menyumbang.
 * Skor risiko = peluang x 100. Alasan = fitur dengan sumbangan (bobot x defisit) terbesar, jadi setiap skor bisa
 * ditelusuri ke data. Bobot awal ditentukan manual (prior); kalibrasi dari kelas yang sudah selesai (fit()) menggeser
 * bobot dengan regularisasi ke arah prior agar tetap stabil saat data sedikit.
 */
final class RiskModel
{
    public const FEATURES = ['attendance', 'lms', 'quiz', 'assignment', 'late', 'practice', 'inactive'];

    public const PRIOR_VERSION = 'ew-prior-2026-11-17';

    /** Bobot awal (logit). Intercept -3,2: peserta tanpa defisit punya peluang tidak lulus sekitar 4%. */
    public const PRIOR = [
        'intercept' => -3.2,
        'weights' => ['attendance' => 5.0, 'lms' => 3.0, 'quiz' => 2.5, 'assignment' => 2.0, 'late' => 2.0, 'practice' => 2.5, 'inactive' => 1.5],
    ];

    public const LABELS = [
        'attendance' => 'Kehadiran',
        'lms' => 'Progres materi',
        'quiz' => 'Nilai kuis',
        'assignment' => 'Nilai tugas',
        'late' => 'Tugas terlambat / belum dikumpulkan',
        'practice' => 'Praktik di logbook',
        'inactive' => 'Aktivitas belajar',
    ];

    /** @param  array<string, float>  $weights */
    public function __construct(public readonly float $intercept, public readonly array $weights, public readonly string $version) {}

    public static function prior(): self
    {
        return new self(self::PRIOR['intercept'], self::PRIOR['weights'], self::PRIOR_VERSION);
    }

    /** @param  array{intercept: float, weights: array<string, float>}  $params */
    public static function fromParams(array $params, string $version): self
    {
        $weights = [];
        foreach (self::FEATURES as $f) {
            $weights[$f] = (float) ($params['weights'][$f] ?? self::PRIOR['weights'][$f]);
        }

        return new self((float) ($params['intercept'] ?? self::PRIOR['intercept']), $weights, $version);
    }

    /** @return array{intercept: float, weights: array<string, float>} */
    public function params(): array
    {
        return ['intercept' => round($this->intercept, 4), 'weights' => array_map(fn ($w) => round($w, 4), $this->weights)];
    }

    /**
     * @param  array<string, float|null>  $x  defisit per fitur
     * @return array{probability: float, score: int, contributions: array<string, float>}
     */
    public function predict(array $x): array
    {
        $z = $this->intercept;
        $contributions = [];
        foreach (self::FEATURES as $f) {
            $v = $x[$f] ?? null;
            if ($v === null) {
                continue;
            }
            $contributions[$f] = $this->weights[$f] * $v;
            $z += $contributions[$f];
        }
        arsort($contributions);
        $p = self::sigmoid($z);

        return ['probability' => $p, 'score' => (int) round($p * 100), 'contributions' => $contributions];
    }

    /**
     * Kalibrasi dari contoh berlabel (kelas selesai). Gradient descent pada log-loss rata-rata ditambah penalti L2
     * terhadap bobot prior. Deterministik (tanpa acak), jadi hasilnya bisa diulang.
     *
     * @param  list<array{x: array<string, float|null>, y: bool}>  $samples
     */
    public static function fit(array $samples, string $version, float $lambda = 0.02, int $iterations = 3000, float $rate = 0.5): self
    {
        $prior = self::prior();
        $b = $prior->intercept;
        $w = $prior->weights;
        $n = count($samples);
        if ($n === 0) {
            return new self($b, $w, $version);
        }
        $rows = array_map(fn ($s) => [array_map(fn ($f) => (float) ($s['x'][$f] ?? 0.0), self::FEATURES), $s['y'] ? 1.0 : 0.0], $samples);
        $keys = self::FEATURES;
        for ($it = 0; $it < $iterations; $it++) {
            $gb = 0.0;
            $gw = array_fill(0, count($keys), 0.0);
            foreach ($rows as [$xs, $y]) {
                $z = $b;
                foreach ($keys as $i => $k) {
                    $z += $w[$k] * $xs[$i];
                }
                $err = self::sigmoid($z) - $y;
                $gb += $err;
                foreach ($keys as $i => $k) {
                    $gw[$i] += $err * $xs[$i];
                }
            }
            $b -= $rate * ($gb / $n);
            foreach ($keys as $i => $k) {
                $w[$k] -= $rate * ($gw[$i] / $n + 2 * $lambda * ($w[$k] - $prior->weights[$k]));
            }
        }

        return new self($b, $w, $version);
    }

    public static function sigmoid(float $z): float
    {
        return 1.0 / (1.0 + exp(-max(-40.0, min(40.0, $z))));
    }
}
