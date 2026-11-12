<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris mutasi rekening (kredit) yang dicocokkan dengan transaksi pembayaran. */
class BankStatementLine extends Model
{
    public const STATUSES = ['unmatched' => 'Belum cocok', 'matched' => 'Cocok', 'ignored' => 'Diabaikan'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_on' => 'date', 'amount' => 'decimal:2', 'matched_at' => 'datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
