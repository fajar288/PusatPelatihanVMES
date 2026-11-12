<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat percobaan kirim per kanal: terkirim / gagal (PRD §5.12). Pengiriman engine (5D) mencatat delivery_id + attempt. */
class NotificationLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const STATUSES = ['sent' => 'Terkirim', 'failed' => 'Gagal'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'delivery_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('services.public_api.log_retention_days', 90) * 2));
    }
}
