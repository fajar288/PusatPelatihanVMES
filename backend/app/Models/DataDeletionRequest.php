<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan penghapusan data pribadi oleh pemilik akun.
 * Alur: pending -> approved (lalu otomatis completed) | rejected; pending -> cancelled oleh peserta.
 * Satu permintaan aktif (pending/approved) per akun.
 */
class DataDeletionRequest extends Model
{
    public const STATUSES = [
        'pending' => 'Menunggu diproses',
        'approved' => 'Disetujui, sedang diproses',
        'rejected' => 'Ditolak',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    /** Status yang menghalangi permintaan baru. */
    public const ACTIVE = ['pending', 'approved'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'auto_rejected' => 'boolean',
            'blockers' => 'array',
            'summary' => 'array',
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE);
    }

    /** Permintaan aktif yang tenggatnya lewat atau tinggal beberapa hari (config privacy.deletion_warning_days). */
    public function scopeNearDeadline(Builder $q): Builder
    {
        return $q->active()->where('due_at', '<=', now()->addDays((int) config('privacy.deletion_warning_days', 3)));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function isNearDeadline(): bool
    {
        return $this->isActive() && $this->due_at !== null && $this->due_at->lte(now()->addDays((int) config('privacy.deletion_warning_days', 3)));
    }

    public function statusLabel(): string
    {
        return $this->auto_rejected && $this->status === 'rejected' ? 'Ditolak otomatis' : (self::STATUSES[$this->status] ?? $this->status);
    }
}
