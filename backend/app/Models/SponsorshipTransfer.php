<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pengalihan sponsor enrollment sebagai workflow resmi (PRD Roadmap 5 §20.20, R7/R8).
 * Setelah diputuskan (ditolak/diterapkan/dibatalkan) catatan tidak bisa diubah atau dihapus.
 */
class SponsorshipTransfer extends Model
{
    public const STATUSES = [
        'requested' => 'Menunggu otorisasi',
        'approved' => 'Disetujui (menunggu tanggal efektif)',
        'rejected' => 'Ditolak',
        'applied' => 'Diterapkan',
        'cancelled' => 'Dibatalkan',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (SponsorshipTransfer $t): void {
            if (in_array($t->getOriginal('status'), ['rejected', 'applied', 'cancelled'], true)) {
                throw new LogicException('Transfer sponsor yang sudah final tidak bisa diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Transfer sponsor tidak bisa dihapus.'));
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'to_unit_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', 'approved')->whereDate('effective_date', '<=', today());
    }

    public static function nextCode(): string
    {
        $prefix = 'STR-'.now()->format('Ym').'-';
        $last = static::query()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');

        return $prefix.str_pad((string) ($last ? ((int) substr($last, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
