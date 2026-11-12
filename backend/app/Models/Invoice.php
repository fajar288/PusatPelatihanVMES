<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice (PRD Roadmap 5 §5, §13): tagihan untuk pendaftaran (individu/kolektif) atau Corporate Training Request.
 * Pembayaran MANUAL — status hanya berubah lewat verifikasi finance (PaymentService), bukan klaim pembayar.
 */
class Invoice extends Model
{
    public const STATUSES = [
        'pending' => 'Menunggu pembayaran',
        'paid' => 'Lunas',
        'failed' => 'Pembayaran ditolak',
        'expired' => 'Kedaluwarsa',
        'refunded' => 'Dikembalikan',
        'cancelled' => 'Dibatalkan',
    ];

    /** Status yang masih bisa menerima bukti pembayaran. */
    public const PAYABLE = ['pending', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2',
            'amount_paid' => 'decimal:2', 'amount_refunded' => 'decimal:2', 'installment_no' => 'integer', 'installment_total' => 'integer',
            'issued_at' => 'datetime', 'due_date' => 'date', 'paid_at' => 'datetime', 'expired_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class)->withTrashed();
    }

    public function trainingRequest(): BelongsTo
    {
        return $this->belongsTo(TrainingRequest::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->latest('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->latest('id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::PAYABLE);
    }

    /** Tagihan perusahaan: training request organisasi itu atau pendaftaran kolektif yang ditautkan ke organisasi itu. */
    public function scopeForOrganization(Builder $q, int $organizationId): Builder
    {
        return $q->where('organization_id', $organizationId);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE, true);
    }

    public function outstanding(): float
    {
        return round(max(0, (float) $this->total - (float) $this->amount_paid), 2);
    }

    public function hasPendingTransaction(): bool
    {
        return $this->transactions()->where('status', 'pending')->exists();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Label sumber tagihan: nomor pendaftaran atau kode permintaan training. */
    public function sourceLabel(): string
    {
        return $this->registration?->registration_number ?? $this->trainingRequest?->code ?? '-';
    }

    /** Nomor invoice INV-YYYYMM-0001. */
    public static function nextNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ym').'-';
        $last = static::query()->where('invoice_number', 'like', $prefix.'%')->orderByDesc('invoice_number')->value('invoice_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'invoice_number';
    }
}
