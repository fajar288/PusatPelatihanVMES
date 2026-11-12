<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Rekening tujuan pembayaran manual: rekening bank atau nomor Virtual Account statis. */
class PaymentAccount extends Model
{
    public const TYPES = [
        'bank_transfer' => 'Transfer bank',
        'virtual_account' => 'Virtual Account',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    /** Rekening aktif sesuai urutan tampil. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Satu baris ringkas untuk email, mis. "BCA (Virtual Account) 1234567890 a.n. PT ...". */
    public function summary(): string
    {
        $type = $this->type === 'virtual_account' ? ' (Virtual Account)' : '';

        return "{$this->bank_name}{$type} {$this->account_number} a.n. {$this->account_name}";
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'typeLabel' => $this->typeLabel(),
            'bank' => $this->bank_name,
            'number' => $this->account_number,
            'name' => $this->account_name,
            'instructions' => $this->instructions,
        ];
    }
}
