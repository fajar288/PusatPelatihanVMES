<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengiriman notifikasi: penerima × kanal (PRD Roadmap 5 §6.1).
 * Status: queued → sent → delivered → read, atau failed setelah batas retry; cancelled bila dibatalkan admin.
 * Isi sudah dirender saat dibuat (`content`) sehingga retry mengirim pesan yang sama dan versi template tercatat.
 */
class NotificationDelivery extends Model
{
    public const STATUSES = [
        'queued' => 'Antre (Queued)',
        'sent' => 'Terkirim (Sent)',
        'delivered' => 'Diterima (Delivered)',
        'read' => 'Dibaca (Read)',
        'failed' => 'Gagal (Failed)',
        'cancelled' => 'Dibatalkan',
    ];

    /** Urutan kemajuan status — status tidak pernah mundur (mis. tanda terima "delivered" setelah "read"). */
    public const PROGRESS = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'scheduled_at' => 'datetime', 'next_attempt_at' => 'datetime', 'locked_at' => 'datetime',
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'event_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'delivery_id')->orderBy('id');
    }

    /** Siap diproses: antre, waktunya tiba (jadwal & jeda retry), tidak sedang dikunci proses lain. */
    public function scopeDue(Builder $q): Builder
    {
        $stale = now()->subMinutes((int) config('notifications.retry.stale_lock_minutes', 10));

        return $q->where('status', 'queued')
            ->where(fn (Builder $w) => $w->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('locked_at')->orWhere('locked_at', '<', $stale));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['read', 'failed', 'cancelled'], true);
    }
}
