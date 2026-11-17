<?php

namespace App\Services;

use App\Models\AiUsageRecord;
use App\Models\AiUseCaseSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Ai\AiUseCases;
use App\Support\Chatbot\ChatReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gerbang tata kelola semua use case AI: status nyala/mati (flag global, flag use case, API key, jeda admin, batas harian),
 * pencatatan pemakaian token + perkiraan biaya, dan tombol jeda admin (tercatat di audit_logs).
 */
class AiGovernance
{
    public const REASONS = [
        'global_off' => 'Fitur AI dimatikan (AI_ENABLED=false)',
        'flag_off' => 'Use case ini belum dinyalakan',
        'no_api_key' => 'ANTHROPIC_API_KEY belum diisi',
        'paused' => 'Dijeda admin',
        'limit_calls' => 'Batas panggilan harian tercapai',
        'limit_tokens' => 'Batas token harian tercapai',
    ];

    /** Status pemakaian yang dihitung ke batas harian (penolakan karena jeda/batas tidak dihitung). */
    private const BILLABLE = ['ok', 'refused', 'error'];

    /**
     * @return array{key:string,available:bool,reason:?string,reason_label:?string,paused:bool,
     *     usage_today:array{calls:int,tokens:int,cost_usd:float}}
     */
    public function status(string $useCase): array
    {
        $uc = AiUseCases::get($useCase);
        $paused = $this->isPaused($useCase);
        $usage = $this->usageToday($useCase);

        $reason = match (true) {
            ! $uc['independent'] && ! config('ai.enabled') => 'global_off',
            ! $uc['enabled'] => 'flag_off',
            $uc['method'] === AiUseCases::METHOD_LLM && blank($uc['independent'] ? config('chatbot.api_key') : config('ai.api_key')) => 'no_api_key',
            $paused => 'paused',
            $uc['method'] === AiUseCases::METHOD_LLM && $uc['daily_calls'] > 0 && $usage['calls'] >= $uc['daily_calls'] => 'limit_calls',
            $uc['method'] === AiUseCases::METHOD_LLM && $uc['daily_tokens'] > 0 && $usage['tokens'] >= $uc['daily_tokens'] => 'limit_tokens',
            default => null,
        };

        return [
            'key' => $useCase,
            'available' => $reason === null,
            'reason' => $reason,
            'reason_label' => $reason ? self::REASONS[$reason] : null,
            'paused' => $paused,
            'usage_today' => $usage,
        ];
    }

    public function available(string $useCase): bool
    {
        return $this->status($useCase)['available'];
    }

    /** @return array{calls:int,tokens:int,cost_usd:float} */
    public function usageToday(string $useCase): array
    {
        $row = AiUsageRecord::query()
            ->where('use_case', $useCase)
            ->whereIn('status', self::BILLABLE)
            ->where('created_at', '>=', today())
            ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) AS tokens, COALESCE(SUM(cost_usd), 0) AS cost')
            ->first();

        return ['calls' => (int) $row?->calls, 'tokens' => (int) $row?->tokens, 'cost_usd' => round((float) $row?->cost, 6)];
    }

    /** Perkiraan biaya (USD) dari harga per 1 juta token di config/ai.php. Model tak dikenal memakai harga claude-opus-5-5. */
    public function cost(?string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $prices = (array) config('ai.pricing');
        $p = $prices[$model ?? ''] ?? $prices[$this->priceKey($model)] ?? $prices['claude-opus-5-5'] ?? ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

        return round(($input * $p['input'] + $output * $p['output'] + $cacheRead * $p['cache_read'] + $cacheWrite * $p['cache_write']) / 1_000_000, 6);
    }

    /** Catat satu panggilan model (atau penolakan) beserta token dan perkiraan biaya. */
    public function recordUsage(
        string $useCase,
        string $status,
        ?ChatReply $reply = null,
        ?int $latencyMs = null,
        ?string $error = null,
        ?int $insightId = null,
        float $priceFactor = 1.0,
    ): AiUsageRecord {
        $uc = AiUseCases::get($useCase);
        $model = $reply?->model ?? $uc['model'];

        return AiUsageRecord::query()->create([
            'use_case' => $useCase,
            'model' => $model,
            'prompt_version' => $uc['prompt_version'],
            'ai_insight_id' => $insightId,
            'status' => $status,
            'input_tokens' => $reply?->inputTokens ?? 0,
            'output_tokens' => $reply?->outputTokens ?? 0,
            'cache_read_tokens' => $reply?->cacheReadTokens ?? 0,
            'cache_write_tokens' => $reply?->cacheWriteTokens ?? 0,
            // priceFactor 0,5 untuk Message Batches (harga batch setengah harga normal).
            'cost_usd' => $reply ? round($this->cost($model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens) * $priceFactor, 6) : 0,
            'latency_ms' => $latencyMs,
            'error' => $error ? Str::limit($error, 250) : null,
        ]);
    }

    public function isPaused(string $useCase): bool
    {
        return (bool) AiUseCaseSetting::query()->whereKey($useCase)->value('paused');
    }

    /** Jeda use case (tombol mati darurat). Tidak bisa menyalakan fitur yang flag env-nya mati. */
    public function pause(string $useCase, User $actor, ?string $note = null): void
    {
        $this->setPaused($useCase, true, $actor, $note);
    }

    public function resume(string $useCase, User $actor): void
    {
        $this->setPaused($useCase, false, $actor, null);
    }

    private function setPaused(string $useCase, bool $paused, User $actor, ?string $note): void
    {
        AiUseCases::get($useCase); // validasi nama use case
        DB::transaction(function () use ($useCase, $paused, $actor, $note): void {
            $setting = AiUseCaseSetting::query()->find($useCase) ?? new AiUseCaseSetting(['use_case' => $useCase]);
            $before = ['use_case' => $useCase, 'paused' => (bool) $setting->paused];
            $setting->fill(['paused' => $paused, 'note' => $note, 'updated_by' => $actor->id])->save();
            // auditable_id bertipe angka, jadi nama use case dicatat di isi log, bukan sebagai auditable.
            AuditLog::record($paused ? 'ai.use_case_paused' : 'ai.use_case_resumed', null, $before, ['use_case' => $useCase, 'paused' => $paused, 'note' => $note], $actor);
        });
    }

    /** "claude-opus-5-5-20260101" atau "anthropic.claude-opus-5-5" dipetakan ke kunci harga "claude-opus-5-5". */
    private function priceKey(?string $model): string
    {
        foreach (array_keys((array) config('ai.pricing')) as $key) {
            if ($model !== null && str_contains($model, $key)) {
                return $key;
            }
        }

        return '';
    }
}
