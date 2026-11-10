<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tagihan & bukti transfer satu pendaftaran (subset PRD §5.11). */
class Payment extends Model
{
    public const STATUSES = ['unpaid', 'submitted', 'verified', 'rejected', 'refunded'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date', 'proof_uploaded_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Nomor invoice INV-YYYYMM-0001. */
    public static function nextNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ym').'-';
        $last = static::query()->where('invoice_number', 'like', $prefix.'%')->orderByDesc('invoice_number')->value('invoice_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
