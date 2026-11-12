<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunci `Idempotency-Key` per klien API (PRD Roadmap 5 §7.1 "Idempotency key untuk transaksi penting").
 * Respons sukses disimpan dan diputar ulang untuk permintaan dengan key + isi yang sama selama masa berlaku.
 */
class ApiIdempotencyKey extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Masa berlaku key (jam). */
    public const TTL_HOURS = 24;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'expires_at' => 'datetime', 'response_status' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** `php artisan model:prune` menghapus key kedaluwarsa. */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
