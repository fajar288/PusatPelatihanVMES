<?php

namespace App\Support\Chatbot;

/** Hasil satu panggilan model: teks jawaban + metrik untuk log. */
final class ChatReply
{
    public function __construct(
        public readonly string $text,
        public readonly bool $refused = false,
        public readonly ?string $model = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}
}
