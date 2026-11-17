<?php

namespace App\Support\Chatbot;

/**
 * Hasil satu panggilan model: teks jawaban + metrik untuk log.
 *
 * `segments` hanya terisi bila permintaan memakai dokumen dengan citations: jawaban dipecah per blok teks, dan blok
 * yang bersumber dari dokumen membawa daftar kutipan (indeks dokumen, judul, teks yang dikutip). Gabungan teks semua
 * segmen sama dengan `text`.
 */
final class ChatReply
{
    /**
     * @param  list<array{text:string,citations:list<array{document_index:int,document_title:?string,cited_text:string}>}>  $segments
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $refused = false,
        public readonly ?string $model = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly array $segments = [],
    ) {}
}
