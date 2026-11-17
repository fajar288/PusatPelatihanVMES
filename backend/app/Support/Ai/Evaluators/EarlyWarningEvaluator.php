<?php

namespace App\Support\Ai\Evaluators;

use App\Models\AiModelVersion;
use App\Services\AiEarlyWarningService;

/**
 * Early warning: precision, recall, dan F1 model aktif dibandingkan baseline aturan Dashboard Eksekutif, pada semua kelas
 * yang sudah selesai (label: tidak lulus). Bila model aktif hasil kalibrasi, metrik holdout saat kalibrasi ikut dilaporkan
 * karena evaluasi pada semua data selesai ikut memakai data latih (in-sample).
 */
class EarlyWarningEvaluator implements Evaluator
{
    public function __construct(private readonly AiEarlyWarningService $service) {}

    public function evaluate(): array
    {
        $r = $this->service->evaluate();
        $active = AiModelVersion::active(AiEarlyWarningService::USE_CASE);
        $notes = [];
        if ($r['n'] === 0) {
            $notes[] = 'Belum ada kelas selesai dengan hasil lulus/tidak lulus, metrik belum bisa dihitung.';
        } elseif ($r['positives'] === 0) {
            $notes[] = 'Belum ada peserta tidak lulus, recall belum bisa dihitung.';
        }
        if ($r['beats_baseline'] === false) {
            $notes[] = 'Model kalah dari baseline aturan pada data ini. Laporkan ke pemilik proyek; jangan nyalakan sebelum diperbaiki.';
        }
        if ($active) {
            $notes[] = 'Model aktif hasil kalibrasi ('.$active->version.'); angka utama ikut memakai data latih, pakai metrik holdout untuk keputusan.';
        }

        return [
            'metrics' => [
                'model_version' => $r['model_version'],
                'threshold' => $r['threshold'],
                'samples' => $r['n'],
                'failed' => $r['positives'],
                'precision' => $r['model']['precision'],
                'recall' => $r['model']['recall'],
                'f1' => $r['model']['f1'],
                'baseline_precision' => $r['baseline']['precision'],
                'baseline_recall' => $r['baseline']['recall'],
                'baseline_f1' => $r['baseline']['f1'],
                'beats_baseline' => match ($r['beats_baseline']) {
                    true => 'ya',
                    false => 'tidak',
                    null => 'belum dapat dinilai',
                },
            ],
            'baseline' => ['description' => 'Aturan peserta berisiko di Dashboard Eksekutif', 'metrics' => $r['baseline']],
            'holdout' => $active?->metrics['holdout'] ?? null,
            'notes' => $notes,
        ];
    }
}
