<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Identitas digital peserta (PRD §5.6): satu akun = satu member, nomor member tetap. */
class Member extends Model
{
    public const STATUSES = ['registered', 'active', 'alumni', 'inactive'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'communication_pref' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class)->latest();
    }

    /** Nomor member MBR-YYYY-000001 (urut per tahun). */
    public static function nextNumber(): string
    {
        $prefix = 'MBR-'.now()->format('Y').'-';
        $last = static::query()->where('member_number', 'like', $prefix.'%')->orderByDesc('member_number')->value('member_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
