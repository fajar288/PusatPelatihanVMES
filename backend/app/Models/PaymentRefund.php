<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Refund manual (PRD Roadmap 5 §5.1): alasan, penyetuju, waktu, referensi. Dana dikembalikan di luar sistem. */
class PaymentRefund extends Model
{
    public const STATUSES = [
        'requested' => 'Diajukan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'completed' => 'Selesai ditransfer',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'requested_at' => 'datetime', 'decided_at' => 'datetime', 'completed_at' => 'datetime', 'transferred_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function nextCode(): string
    {
        $prefix = 'RFD-'.now()->format('Ym').'-';
        $last = static::query()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
