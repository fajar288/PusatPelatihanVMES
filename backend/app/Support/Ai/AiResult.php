<?php

namespace App\Support\Ai;

use App\Models\AiUsageRecord;

/**
 * Hasil satu panggilan AI lewat AiModelRunner. Bila `ok` false, pemanggil WAJIB kembali ke aturan/proses manual.
 * status: ok | unavailable (mati/jeda/batas) | refused | error | blocked (data pribadi terdeteksi, tidak dikirim).
 */
final class AiResult
{
    /** @param  array<mixed>|null  $data */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $text = null,
        public readonly ?array $data = null,
        public readonly ?string $model = null,
        public readonly ?string $promptVersion = null,
        public readonly ?AiUsageRecord $usage = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
