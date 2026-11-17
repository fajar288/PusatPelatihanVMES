<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiUsageRecord;
use App\Support\Ai\AiUseCases;
use App\Support\Ai\Evaluators\Evaluator;
use App\Support\Ai\Evaluators\PublicChatbotEvaluator;
use App\Support\Ai\Metrics;
use Illuminate\Support\Facades\Storage;

/**
 * Laporan evaluasi offline per use case (perintah ai:evaluate). Isi laporan:
 *  - status fitur dan kesiapan data (AiReadiness);
 *  - metrik tata kelola yang berlaku untuk semua use case: jumlah insight per status, acceptance rate tinjauan admin,
 *    helpful rate dari umpan balik pengguna, pemakaian & biaya 30 hari, tingkat galat/penolakan model;
 *  - metrik khusus use case (Evaluator) dibandingkan baseline, bila evaluatornya sudah tersedia.
 * Selama data belum cukup, laporan diberi tanda "Data belum cukup": hasilnya hanya membuktikan pipeline berjalan.
 */
class AiEvaluationService
{
    /** @var array<string, class-string<Evaluator>> */
    public const EVALUATORS = [
        'public_chatbot' => PublicChatbotEvaluator::class,
    ];

    public function __construct(private AiGovernance $governance, private AiReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function evaluate(string $useCase): array
    {
        $uc = AiUseCases::get($useCase);
        $readiness = $this->readiness->report($useCase);
        $evaluator = isset(self::EVALUATORS[$useCase]) ? app(self::EVALUATORS[$useCase]) : null;

        $insights = AiInsight::query()->where('use_case', $useCase)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $reviewed = AiInsight::query()->where('use_case', $useCase)->whereNotNull('reviewer_id')
            ->whereIn('status', [AiInsight::STATUS_APPROVED, AiInsight::STATUS_REJECTED])->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $approved = (int) ($reviewed[AiInsight::STATUS_APPROVED] ?? 0);
        $rejected = (int) ($reviewed[AiInsight::STATUS_REJECTED] ?? 0);
        $fb = AiInsightFeedback::query()->whereHas('insight', fn ($q) => $q->where('use_case', $useCase))
            ->selectRaw('COUNT(*) AS n, SUM(CASE WHEN helpful THEN 1 ELSE 0 END) AS up')->first();
        $usage = AiUsageRecord::query()->where('use_case', $useCase)->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("COUNT(*) AS calls,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) AS refused,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN status IN ('limited', 'paused') THEN 1 ELSE 0 END) AS denied,
                COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) AS tokens,
                COALESCE(SUM(cost_usd), 0) AS cost")->first();
        $billable = (int) $usage?->ok + (int) $usage?->refused + (int) $usage?->errors;

        $specific = $evaluator?->evaluate();

        return [
            'use_case' => $useCase,
            'label' => $uc['label'],
            'method' => $uc['method'],
            'generated_at' => now()->toIso8601String(),
            'model' => $uc['model'],
            'prompt_version' => $uc['prompt_version'],
            'status' => $this->governance->status($useCase),
            'readiness' => $readiness,
            'data_sufficient' => $readiness['ready'],
            'governance' => [
                'insights' => $insights->all(),
                'acceptance_rate' => Metrics::rate($approved, $approved + $rejected),
                'feedback_count' => (int) $fb?->n,
                'helpful_rate' => Metrics::rate((int) $fb?->up, (int) $fb?->n),
                'usage_30d' => [
                    'calls' => (int) $usage?->calls,
                    'tokens' => (int) $usage?->tokens,
                    'cost_usd' => round((float) $usage?->cost, 4),
                    'refusal_rate' => Metrics::rate((int) $usage?->refused, $billable),
                    'error_rate' => Metrics::rate((int) $usage?->errors, $billable),
                    'denied_by_limit_or_pause' => (int) $usage?->denied,
                ],
            ],
            'metrics' => $uc['metrics'],
            'baseline' => $uc['baseline'],
            'specific' => $specific,
            'notes' => array_values(array_filter([
                $readiness['ready'] ? null : 'Data belum cukup: angka di laporan ini hanya membuktikan pipeline evaluasi berjalan, belum bisa dipakai untuk memutuskan fitur dinyalakan.',
                $evaluator ? null : 'Evaluasi khusus use case ini belum tersedia; laporan hanya berisi metrik tata kelola.',
                ...($specific['notes'] ?? []),
            ])),
        ];
    }

    /** Simpan laporan sebagai JSON di storage/app/<evaluation_dir>/ dan kembalikan path relatifnya. */
    public function store(array $report): string
    {
        $path = trim((string) config('ai.evaluation_dir'), '/').'/'.$report['use_case'].'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
