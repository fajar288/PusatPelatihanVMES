<?php

namespace App\Support\Ai\Evaluators;

use App\Services\AiRecommendationService;

/**
 * Rekomendasi training: uji offline hit rate@3 (program berikutnya yang benar-benar diambil member masuk 3 teratas)
 * dibanding baseline "terpopuler di bidang yang sama", ditambah metrik pemakaian CTR, konversi, dan repeat enrollment.
 */
class RecommendationEvaluator implements Evaluator
{
    public function __construct(private readonly AiRecommendationService $service) {}

    public function evaluate(): array
    {
        $b = $this->service->backtest();
        $o = $this->service->online();
        $notes = [];
        if ($b['n'] === 0) {
            $notes[] = 'Belum ada member dengan 2 program atau lebih, uji offline belum bisa dihitung.';
        }
        if ($b['beats_baseline'] === false) {
            $notes[] = 'Rekomendasi kalah dari baseline terpopuler pada uji offline. Laporkan ke pemilik proyek.';
        }
        if ($o['impressions'] === 0) {
            $notes[] = 'Belum ada rekomendasi yang ditampilkan, CTR dan konversi belum bisa dihitung.';
        }

        return [
            'metrics' => [
                'backtest_members' => $b['n'],
                'hit_rate_at_3' => $b['hit_rate_at_3'],
                'baseline_hit_rate_at_3' => $b['baseline_hit_rate_at_3'],
                'beats_baseline' => match ($b['beats_baseline']) {
                    true => 'ya',
                    false => 'tidak',
                    null => 'belum dapat dinilai',
                },
                'impressions' => $o['impressions'],
                'clicks' => $o['clicks'],
                'ctr' => $o['ctr'],
                'conversions' => $o['conversions'],
                'conversion_rate' => $o['conversion_rate'],
                'alumni_shown' => $o['alumni_shown'],
                'repeat_enrollment_rate' => $o['repeat_enrollment_rate'],
            ],
            'baseline' => ['description' => 'Program terpopuler di bidang yang sama', 'hit_rate_at_3' => $b['baseline_hit_rate_at_3']],
            'notes' => $notes,
        ];
    }
}
