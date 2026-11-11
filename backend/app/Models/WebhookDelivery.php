<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pengiriman webhook ke klien API (payload, percobaan, respons terakhir). */
class WebhookDelivery extends Model
{
    use MassPrunable;

    public const STATUSES = ['pending' => 'Menunggu', 'delivered' => 'Terkirim', 'failed' => 'Gagal'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('services.public_api.log_retention_days', 90)));
    }
}
