<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kode promo (PRD §5.11): persen atau nominal, masa berlaku, kuota pemakaian, opsional per paket. */
class PromoCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'min_total' => 'decimal:2', 'max_discount' => 'decimal:2', 'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    /** Alasan tidak valid, atau null bila bisa dipakai. */
    public function invalidReason(?float $total, ?int $packageId): ?string
    {
        if (! $this->is_active) {
            return 'Kode promo tidak aktif.';
        }
        if ($this->valid_from && today()->lt($this->valid_from)) {
            return 'Kode promo belum berlaku.';
        }
        if ($this->valid_until && today()->gt($this->valid_until)) {
            return 'Kode promo sudah kedaluwarsa.';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Kuota kode promo sudah habis.';
        }
        if ($this->package_id && $packageId !== $this->package_id) {
            return 'Kode promo tidak berlaku untuk paket ini.';
        }
        if ($this->min_total !== null && ($total ?? 0) < (float) $this->min_total) {
            return 'Total belum memenuhi minimum promo.';
        }

        return null;
    }

    public function discountFor(float $total): float
    {
        $d = $this->type === 'percent' ? $total * (float) $this->value / 100 : (float) $this->value;
        if ($this->max_discount !== null) {
            $d = min($d, (float) $this->max_discount);
        }

        return round(min($d, $total), 2);
    }
}
