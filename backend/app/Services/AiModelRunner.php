<?php

namespace App\Services;

use App\Support\Ai\AiPrompt;
use App\Support\Ai\AiResult;
use App\Support\Ai\AiText;
use App\Support\Ai\AiUseCases;
use App\Support\Ai\PersonalData;
use App\Support\Chatbot\ChatModel;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Satu-satunya jalur use case AI (selain chatbot publik) untuk memanggil Claude.
 *
 * Urutan: cek status (flag global, flag use case, API key, jeda admin, batas harian) → pengaman data pribadi
 * (email/telepon/NIK tidak boleh terkirim) → panggil model dengan model/effort/batas token/timeout use case →
 * catat pemakaian + biaya → normalkan teks (tanpa em dash / en dash). Galat, penolakan model, atau fitur mati
 * menghasilkan AiResult yang tidak ok; pemanggil kembali ke aturan atau proses manual.
 */
class AiModelRunner
{
    public function __construct(private ChatModel $model, private AiGovernance $governance) {}

    /**
     * @param  list<array{type:string,text:string,cache?:bool}>  $system
     * @param  list<array{role:'user'|'assistant',content:string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  structured output; hasilnya ada di AiResult::$data
     */
    public function run(string $useCase, array $system, array $messages, ?array $jsonSchema = null): AiResult
    {
        $uc = AiUseCases::get($useCase);
        if ($uc['method'] !== AiUseCases::METHOD_LLM || $uc['independent']) {
            throw new InvalidArgumentException("Use case $useCase tidak memakai jalur model bersama.");
        }

        $status = $this->governance->status($useCase);
        if (! $status['available']) {
            // Kejadian jeda/batas dicatat; fitur yang memang belum dinyalakan tidak perlu dicatat setiap kali.
            if (in_array($status['reason'], ['paused', 'limit_calls', 'limit_tokens'], true)) {
                $this->governance->recordUsage($useCase, $status['reason'] === 'paused' ? 'paused' : 'limited', error: $status['reason_label']);
            }

            return new AiResult('unavailable', $status['reason'], promptVersion: $uc['prompt_version']);
        }

        try {
            PersonalData::assertClean([array_column($system, 'text'), array_column($messages, 'content')]);
        } catch (Throwable $e) {
            Log::warning("AI $useCase dibatalkan: ".$e->getMessage());
            $usage = $this->governance->recordUsage($useCase, 'error', error: 'Dibatalkan: data pribadi terdeteksi sebelum dikirim');

            return new AiResult('blocked', 'personal_data', promptVersion: $uc['prompt_version'], usage: $usage);
        }

        // Aturan gaya wajib selalu menjadi blok pertama prompt sistem.
        $system = [AiPrompt::styleBlock(), ...$system];

        $started = hrtime(true);
        try {
            $reply = $this->model->reply($system, $messages, array_filter([
                'model' => $uc['model'],
                'effort' => $uc['effort'],
                'max_tokens' => $uc['max_tokens'],
                'timeout' => (float) config('ai.timeout'),
                'json_schema' => $jsonSchema,
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            Log::warning("AI $useCase gagal: ".$e->getMessage());
            $usage = $this->governance->recordUsage($useCase, 'error', latencyMs: $this->ms($started), error: class_basename($e).': '.$e->getMessage());

            return new AiResult('error', 'exception', promptVersion: $uc['prompt_version'], usage: $usage);
        }
        $latency = $this->ms($started);

        if ($reply->refused || $reply->text === '') {
            $usage = $this->governance->recordUsage($useCase, 'refused', $reply, $latency);

            return new AiResult('refused', 'refusal', model: $reply->model, promptVersion: $uc['prompt_version'], usage: $usage);
        }

        $data = null;
        if ($jsonSchema !== null) {
            $decoded = json_decode($reply->text, true);
            if (! is_array($decoded)) {
                $usage = $this->governance->recordUsage($useCase, 'error', $reply, $latency, 'Keluaran bukan JSON yang valid');

                return new AiResult('error', 'invalid_json', model: $reply->model, promptVersion: $uc['prompt_version'], usage: $usage);
            }
            $data = AiInsightService::normalize($decoded);
        }

        $usage = $this->governance->recordUsage($useCase, 'ok', $reply, $latency);

        return new AiResult(
            'ok',
            text: $jsonSchema === null ? AiText::normalize($reply->text) : null,
            data: $data,
            model: $reply->model,
            promptVersion: $uc['prompt_version'],
            usage: $usage,
        );
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
