<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log pengiriman notifikasi per kanal: terkirim / gagal (PRD §5.12). */
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

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('services.public_api.log_retention_days', 90) * 2));
    }
}
