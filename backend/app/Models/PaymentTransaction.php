<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Transaksi pembayaran manual (PRD Roadmap 5 §5, §13): satu bukti transfer yang diunggah (pending) beserta hasil
 * verifikasi finance (paid) atau penolakan (failed). Pembatalan oleh pengunggah sendiri / diganti bukti baru = cancelled.
 */
class PaymentTransaction extends Model
{
    public const STATUSES = [
        'pending' => 'Menunggu verifikasi',
        'paid' => 'Terverifikasi',
        'failed' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ];

    public const METHODS = [
        'bank_transfer' => 'Transfer bank',
        'virtual_account' => 'Virtual Account',
        'cash' => 'Tunai di kantor',
        'other' => 'Lainnya',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'amount_received' => 'decimal:2', 'difference' => 'decimal:2', 'transfer_date' => 'date',
            'submitted_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function statementLine(): HasOne
    {
        return $this->hasOne(BankStatementLine::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    /** Nomor transaksi TRX-YYYYMM-00001. */
    public static function nextCode(): string
    {
        $prefix = 'TRX-'.now()->format('Ym').'-';
        $last = static::query()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
