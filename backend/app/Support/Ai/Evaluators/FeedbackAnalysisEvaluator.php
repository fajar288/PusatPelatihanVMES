<?php

namespace App\Support\Ai\Evaluators;

use App\Services\AiFeedbackService;

/**
 * Analisis umpan balik: akurasi dan macro-F1 sentimen & kategori hasil Claude dibanding label manual admin, dengan
 * baseline kata kunci (tanpa AI). Actionability diukur sebagai F1 "perlu tindakan" terhadap label manual.
 */
class FeedbackAnalysisEvaluator implements Evaluator
{
    public function __construct(private readonly AiFeedbackService $service) {}

    public function evaluate(): array
    {
        $r = $this->service->evaluate();
        $notes = [];
        if ($r['compared'] === 0) {
            $notes[] = 'Belum ada komentar yang punya label manual dan klasifikasi AI sekaligus; beri label di menu Survei evaluasi.';
        } elseif ($r['compared'] < 100) {
            $notes[] = 'Label manual kurang dari 100 komentar, akurasi belum stabil.';
        }
        if ($r['beats_baseline'] === false) {
            $notes[] = 'Klasifikasi AI kalah dari baseline kata kunci pada sentimen. Laporkan ke pemilik proyek.';
        }

        return [
            'metrics' => [
                'labeled' => $r['labeled'],
                'compared' => $r['compared'],
                'sentiment_accuracy' => $r['sentiment']['accuracy'],
                'sentiment_macro_f1' => $r['sentiment']['macro_f1'],
                'category_accuracy' => $r['category']['accuracy'],
                'category_macro_f1' => $r['category']['macro_f1'],
                'actionable_f1' => $r['actionable']['f1'],
                'baseline_sentiment_accuracy' => $r['baseline_sentiment']['accuracy'],
                'baseline_category_accuracy' => $r['baseline_category']['accuracy'],
                'beats_baseline' => match ($r['beats_baseline']) {
                    true => 'ya',
                    false => 'tidak',
                    null => 'belum dapat dinilai',
                },
            ],
            'baseline' => ['description' => 'Kata kunci sederhana tanpa AI', 'sentiment' => $r['baseline_sentiment'], 'category' => $r['baseline_category']],
            'notes' => $notes,
        ];
    }
}
