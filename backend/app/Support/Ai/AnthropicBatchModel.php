<?php

namespace App\Support\Ai;

use Anthropic\Client;
use App\Support\Chatbot\ChatReply;

/**
 * Message Batches lewat SDK resmi anthropic-ai/sdk (`messages->batches`). Prompt sistem di-cache (cacheControl),
 * structured output lewat outputConfig.format. Hasil dibaca per baris (resultsStream), cocokkan dengan custom_id.
 */
class AnthropicBatchModel implements BatchModel
{
    public function __construct(private ?Client $client = null) {}

    public function submit(array $requests): string
    {
        $batch = $this->client()->messages->batches->create(requests: array_map(function (array $r) {
            $o = $r['options'];
            $outputConfig = ['effort' => (string) ($o['effort'] ?? 'low')];
            if (isset($o['json_schema'])) {
                $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $o['json_schema']];
            }

            return [
                'customId' => $r['custom_id'],
                'params' => [
                    'model' => (string) $o['model'],
                    'maxTokens' => (int) ($o['max_tokens'] ?? 1000),
                    'system' => array_map(fn (array $b) => array_filter([
                        'type' => 'text',
                        'text' => $b['text'],
                        'cacheControl' => ($b['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
                    ]), $r['system']),
                    'messages' => $r['messages'],
                    'outputConfig' => $outputConfig,
                ],
            ];
        }, $requests));

        return $batch->id;
    }

    public function status(string $batchId): string
    {
        return (string) $this->client()->messages->batches->retrieve($batchId)->processingStatus;
    }

    public function results(string $batchId): iterable
    {
        foreach ($this->client()->messages->batches->resultsStream($batchId) as $row) {
            $result = $row->result;
            if ($result->type !== 'succeeded') {
                yield ['custom_id' => $row->customID, 'status' => (string) $result->type, 'reply' => null, 'error' => (string) $result->type];

                continue;
            }
            $m = $result->message;
            $text = '';
            foreach ($m->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }
            yield ['custom_id' => $row->customID, 'status' => 'succeeded', 'error' => null, 'reply' => new ChatReply(
                text: trim($text),
                refused: $m->stopReason === 'refusal',
                model: $m->model,
                inputTokens: $m->usage->inputTokens,
                outputTokens: $m->usage->outputTokens,
                cacheReadTokens: (int) $m->usage->cacheReadInputTokens,
                cacheWriteTokens: (int) $m->usage->cacheCreationInputTokens,
            )];
        }
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('ai.api_key'), requestOptions: ['timeout' => (float) config('ai.timeout'), 'maxRetries' => 1]);
    }
}
