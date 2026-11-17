<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu panggilan model AI (atau penolakan karena batas harian / jeda admin) beserta token dan perkiraan biaya. */
class AiUsageRecord extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = [
        'ok' => 'Berhasil',
        'refused' => 'Ditolak model',
        'error' => 'Gagal',
        'limited' => 'Batas harian tercapai',
        'paused' => 'Dijeda admin',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cost_usd' => 'float', 'created_at' => 'datetime'];
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens + $this->cache_read_tokens + $this->cache_write_tokens;
    }

    /** @return BelongsTo<AiInsight, $this> */
    public function insight(): BelongsTo
    {
        return $this->belongsTo(AiInsight::class, 'ai_insight_id');
    }
}
