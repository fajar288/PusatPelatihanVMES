<?php

namespace App\Support\Chatbot;

use Anthropic\Client;

/**
 * Claude lewat SDK resmi anthropic-ai/sdk (Messages API, endpoint beta karena memakai server-side fallback).
 * - Prompt sistem + data stabil di-cache (cacheControl ephemeral) sehingga token input berulang jauh lebih murah.
 * - `fallbacks: 'default'`: bila model menolak (stop_reason refusal), server mengulang di model cadangan sesuai kategori.
 * - Opsi per use case (model, effort, max_tokens, timeout, json_schema) menimpa config/chatbot.php.
 * - Isi pesan boleh berupa daftar blok (mis. dokumen materi dengan citations); kutipan jawaban dikembalikan di segments.
 */
class AnthropicChatModel implements ChatModel
{
    public function __construct(private ?Client $client = null) {}

    public function reply(array $system, array $messages, array $options = []): ChatReply
    {
        $outputConfig = ['effort' => (string) ($options['effort'] ?? config('chatbot.effort'))];
        if (isset($options['json_schema'])) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $options['json_schema']];
        }

        $message = $this->client()->beta->messages->create(
            model: (string) ($options['model'] ?? config('chatbot.model')),
            maxTokens: (int) ($options['max_tokens'] ?? config('chatbot.max_tokens')),
            system: array_map(fn (array $b) => array_filter([
                'type' => 'text',
                'text' => $b['text'],
                'cacheControl' => ($b['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
            ]), $system),
            messages: $messages,
            outputConfig: $outputConfig,
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            requestOptions: isset($options['timeout']) ? ['timeout' => (float) $options['timeout']] : null,
        );

        $text = '';
        $segments = [];
        $cited = false;
        foreach ($message->content as $block) {
            if ($block->type !== 'text') {
                continue;
            }
            $text .= $block->text;
            $citations = [];
            foreach ($block->citations ?? [] as $c) {
                // Dokumen teks biasa menghasilkan char_location; jenis lain tetap dicatat dengan indeks dokumennya.
                if (isset($c->documentIndex)) {
                    $citations[] = ['document_index' => (int) $c->documentIndex, 'document_title' => $c->documentTitle ?? null, 'cited_text' => (string) ($c->citedText ?? '')];
                }
            }
            $cited = $cited || $citations !== [];
            $segments[] = ['text' => $block->text, 'citations' => $citations];
        }

        return new ChatReply(
            text: trim($text),
            segments: $cited ? $segments : [],
            refused: $message->stopReason === 'refusal',
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) $message->usage->cacheReadInputTokens,
            cacheWriteTokens: (int) $message->usage->cacheCreationInputTokens,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('chatbot.api_key'),
            requestOptions: ['timeout' => 60.0, 'maxRetries' => 1],
        );
    }
}
