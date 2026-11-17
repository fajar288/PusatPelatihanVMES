<?php

namespace App\Services;

use App\Models\AiBatch;
use App\Support\Ai\AiPrompt;
use App\Support\Ai\AiResult;
use App\Support\Ai\AiUseCases;
use App\Support\Ai\BatchModel;
use App\Support\Ai\PersonalData;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Jalur Message Batches untuk use case AI (klasifikasi massal). Gerbang sama dengan AiModelRunner: status use case,
 * batas harian (jumlah request di batch hari ini ikut dihitung), pengaman data pribadi, aturan gaya di blok pertama.
 * poll() membaca hasil batch yang selesai, mencatat pemakaian (harga batch = setengah), menormalkan JSON, lalu memanggil
 * callback per item. Dipanggil perintah terjadwal (cPanel tanpa worker permanen).
 */
class AiBatchRunner
{
    public const PRICE_FACTOR = 0.5;

    public function __construct(private readonly BatchModel $model, private readonly AiGovernance $governance) {}

    /**
     * @param  list<array{custom_id: string, ref: array<string, mixed>, messages: list<array{role:string,content:string}>}>  $items
     * @param  list<array{type:string,text:string,cache?:bool}>  $system
     * @param  array<string, mixed>  $jsonSchema
     */
    public function submit(string $useCase, array $items, array $system, array $jsonSchema): ?AiBatch
    {
        $uc = AiUseCases::get($useCase);
        if ($uc['method'] !== AiUseCases::METHOD_LLM || $uc['independent']) {
            throw new InvalidArgumentException("Use case $useCase tidak memakai jalur model bersama.");
        }
        $status = $this->governance->status($useCase);
        if (! $status['available'] || $items === []) {
            if (in_array($status['reason'], ['paused', 'limit_calls', 'limit_tokens'], true)) {
                $this->governance->recordUsage($useCase, $status['reason'] === 'paused' ? 'paused' : 'limited', error: $status['reason_label']);
            }

            return null;
        }
        if ($uc['daily_calls'] > 0) {
            $queued = (int) AiBatch::query()->where('use_case', $useCase)->where('status', 'submitted')->where('submitted_at', '>=', today())->sum('request_count');
            $room = $uc['daily_calls'] - $status['usage_today']['calls'] - $queued;
            if ($room <= 0) {
                $this->governance->recordUsage($useCase, 'limited', error: 'Batas panggilan harian tercapai (termasuk batch yang sedang diproses)');

                return null;
            }
            $items = array_slice($items, 0, $room);
        }

        $clean = [];
        foreach ($items as $it) {
            try {
                PersonalData::assertClean(array_column($it['messages'], 'content'));
                $clean[] = $it;
            } catch (Throwable) {
                $this->governance->recordUsage($useCase, 'error', error: 'Dibatalkan: data pribadi terdeteksi sebelum dikirim');
            }
        }
        if ($clean === []) {
            return null;
        }
        $system = [AiPrompt::styleBlock(), ...$system];
        $options = array_filter(['model' => $uc['model'], 'effort' => $uc['effort'], 'max_tokens' => $uc['max_tokens'], 'json_schema' => $jsonSchema], fn ($v) => $v !== null);
        try {
            $id = $this->model->submit(array_map(fn ($it) => ['custom_id' => $it['custom_id'], 'system' => $system, 'messages' => $it['messages'], 'options' => $options], $clean));
        } catch (Throwable $e) {
            Log::warning("AI batch $useCase gagal dikirim: ".$e->getMessage());
            $this->governance->recordUsage($useCase, 'error', error: 'Batch gagal dikirim: '.class_basename($e).': '.$e->getMessage());

            return null;
        }

        return AiBatch::query()->create([
            'use_case' => $useCase, 'provider_id' => $id, 'status' => 'submitted', 'request_count' => count($clean),
            'items' => collect($clean)->mapWithKeys(fn ($it) => [$it['custom_id'] => $it['ref']])->all(), 'submitted_at' => now(),
        ]);
    }

    /**
     * Proses batch yang sudah selesai. Callback menerima (ref item, AiResult).
     *
     * @param  callable(array<string, mixed>, AiResult): void  $onResult
     * @return array{checked: int, ended: int, results: int}
     */
    public function poll(string $useCase, callable $onResult): array
    {
        $uc = AiUseCases::get($useCase);
        $checked = $ended = $results = 0;
        foreach (AiBatch::query()->where('use_case', $useCase)->where('status', 'submitted')->orderBy('id')->get() as $batch) {
            $checked++;
            try {
                if ($this->model->status((string) $batch->provider_id) !== 'ended') {
                    if ($batch->submitted_at?->lt(now()->subDays(2))) {
                        $batch->update(['status' => 'failed', 'error' => 'Batch tidak selesai dalam 2 hari', 'ended_at' => now()]);
                    }

                    continue;
                }
                $ok = $failed = 0;
                foreach ($this->model->results((string) $batch->provider_id) as $row) {
                    $ref = $batch->items[$row['custom_id']] ?? null;
                    if ($ref === null) {
                        continue;
                    }
                    $result = $this->toResult($useCase, $uc, $row);
                    $result->ok() ? $ok++ : $failed++;
                    $onResult($ref, $result);
                    $results++;
                }
                $batch->update(['status' => 'ended', 'succeeded' => $ok, 'failed' => $failed, 'ended_at' => now()]);
                $ended++;
            } catch (Throwable $e) {
                Log::warning("AI batch {$batch->provider_id} gagal diproses: ".$e->getMessage());
                $batch->update(['error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 250)]);
            }
        }

        return ['checked' => $checked, 'ended' => $ended, 'results' => $results];
    }

    /** @param  array{custom_id: string, status: string, reply: mixed, error: ?string}  $row */
    private function toResult(string $useCase, array $uc, array $row): AiResult
    {
        $reply = $row['reply'];
        if ($row['status'] !== 'succeeded' || $reply === null) {
            $usage = $this->governance->recordUsage($useCase, 'error', error: 'Hasil batch: '.$row['status']);

            return new AiResult('error', $row['status'], promptVersion: $uc['prompt_version'], usage: $usage);
        }
        if ($reply->refused || $reply->text === '') {
            $usage = $this->governance->recordUsage($useCase, 'refused', $reply, priceFactor: self::PRICE_FACTOR);

            return new AiResult('refused', 'refusal', model: $reply->model, promptVersion: $uc['prompt_version'], usage: $usage);
        }
        $decoded = json_decode($reply->text, true);
        if (! is_array($decoded)) {
            $usage = $this->governance->recordUsage($useCase, 'error', $reply, error: 'Keluaran bukan JSON yang valid', priceFactor: self::PRICE_FACTOR);

            return new AiResult('error', 'invalid_json', model: $reply->model, promptVersion: $uc['prompt_version'], usage: $usage);
        }
        $usage = $this->governance->recordUsage($useCase, 'ok', $reply, priceFactor: self::PRICE_FACTOR);

        return new AiResult('ok', data: AiInsightService::normalize($decoded), model: $reply->model, promptVersion: $uc['prompt_version'], usage: $usage);
    }
}
