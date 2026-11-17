<?php

namespace App\Support\Ai\Evaluators;

use App\Models\ChatLog;
use App\Support\Ai\Metrics;

/**
 * Chatbot informasi publik, 30 hari terakhir: tingkat terjawab, unresolved rate, umpan balik pengunjung, dan ketepatan
 * menurut tinjauan admin. Baseline: semua pertanyaan diarahkan ke WhatsApp admin (terjawab otomatis 0%).
 */
class PublicChatbotEvaluator implements Evaluator
{
    public function evaluate(): array
    {
        $logs = ChatLog::query()->where('created_at', '>=', now()->subDays(30))->get(['status', 'feedback', 'review']);
        $n = $logs->count();
        $feedback = $logs->whereNotNull('feedback');
        $reviewed = $logs->whereNotNull('review');

        return [
            'metrics' => [
                'questions' => $n,
                'answered_rate' => Metrics::rate($logs->where('status', 'answered')->count(), $n),
                'unresolved_rate' => Metrics::rate($logs->where('status', 'unresolved')->count(), $n),
                'error_rate' => Metrics::rate($logs->whereIn('status', ['error', 'refused'])->count(), $n),
                'helpful_rate' => Metrics::rate($feedback->where('feedback', 'up')->count(), $feedback->count()),
                'reviewed' => $reviewed->count(),
                'review_correct_rate' => Metrics::rate($reviewed->where('review', 'correct')->count(), $reviewed->count()),
            ],
            'baseline' => ['answered_rate' => 0.0, 'description' => 'Tanpa chatbot, semua pertanyaan dijawab manual lewat WhatsApp admin.'],
            'notes' => $reviewed->count() < 50
                ? ['Tinjauan admin kurang dari 50 percakapan, ketepatan jawaban belum bisa disimpulkan.']
                : [],
        ];
    }
}
