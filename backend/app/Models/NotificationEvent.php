<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Business event untuk Notification Engine (PRD Roadmap 5 §6): dibuat sekali (dedupe_key unik), mencatat pemicu
 * (aktor atau sistem), subjek bisnis, variabel, dan jadwal. Penerima × kanal ada di `notification_deliveries`.
 */
class NotificationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'scheduled_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggerLabel(): string
    {
        return $this->actor_id ? ($this->actor?->name ?? 'Pengguna #'.$this->actor_id) : 'Sistem';
    }
}
