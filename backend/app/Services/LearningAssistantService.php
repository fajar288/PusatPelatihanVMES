<?php

namespace App\Services;

/**
 * Asisten Belajar portal member — kerangka tahap 5G (keputusan mengikat nomor 4, `docs/PROMPT-Roadmap5.md`).
 * Selalu "Belum tersedia": tidak memanggil API AI mana pun dan tidak menyimpan pertanyaan peserta.
 * `AI_CHATBOT_ENABLED` hanya dilaporkan; implementasi sungguhan (jawaban dari materi lesson + sitasi) ada di tahap 5I.5.
 */
class LearningAssistantService
{
    public const ERROR_CODE = 'ai_unavailable';

    public const LABEL = 'Belum tersedia';

    public function flagEnabled(): bool
    {
        return (bool) config('ai.assistant.enabled', false);
    }

    /** Kerangka 5G tidak pernah tersedia, apa pun nilai flag-nya. */
    public function available(): bool
    {
        return false;
    }

    /** @return array{enabled: bool, available: bool, status: string, label: string, message: string} */
    public function status(): array
    {
        return [
            'enabled' => $this->flagEnabled(),
            'available' => $this->available(),
            'status' => 'unavailable',
            'label' => self::LABEL,
            'message' => 'Asisten Belajar sedang disiapkan. Untuk pertanyaan materi, hubungi trainer lewat forum diskusi kelas atau tim admin.',
        ];
    }

    /** @return array{error: array{code: string, message: string}} */
    public function unavailableError(): array
    {
        return ['error' => ['code' => self::ERROR_CODE, 'message' => 'Asisten Belajar belum tersedia.']];
    }
}
