<?php

namespace App\Support\Chatbot;

use Anthropic\Client;

/**
 * Claude lewat SDK resmi anthropic-ai/sdk (Messages API, endpoint beta karena memakai server-side fallback).
 * - Prompt sistem + data katalog di-cache (cacheControl ephemeral) → token input berulang jauh lebih murah.
 * - `fallbacks: 'default'`: bila model menolak (stop_reason refusal), server mengulang di model cadangan sesuai kategori.
 */
class AnthropicChatModel implements ChatModel
{
    public function __construct(private ?Client $client = null) {}

    public function reply(array $system, array $messages): ChatReply
    {
        $message = $this->client()->beta->messages->create(
            model: (string) config('chatbot.model'),
            maxTokens: (int) config('chatbot.max_tokens'),
            system: array_map(fn (array $b) => array_filter([
                'type' => 'text',
                'text' => $b['text'],
                'cacheControl' => ($b['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
            ]), $system),
            messages: $messages,
            outputConfig: ['effort' => (string) config('chatbot.effort')],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new ChatReply(
            text: trim($text),
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
