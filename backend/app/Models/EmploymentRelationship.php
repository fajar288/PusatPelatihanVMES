<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hubungan kerja Member ↔ Organization (PRD Roadmap 5 §4.5, §20.6). Resign/pindah = INACTIVE, bukan menghapus Member.
 * Tidak memberi akses ke enrollment: akses perusahaan tetap mengikuti sponsor enrollment (§20.2).
 */
class EmploymentRelationship extends Model
{
    public const STATUSES = ['active' => 'Aktif', 'inactive' => 'Tidak aktif'];

    public const END_REASONS = ['resigned' => 'Resign', 'transferred' => 'Pindah perusahaan', 'contract_ended' => 'Kontrak berakhir', 'other' => 'Lainnya'];

    public const SOURCES = ['admin' => 'Admin VMES', 'corporate_portal' => 'Corporate Portal', 'bulk_upload' => 'Unggah massal', 'invitation' => 'Undangan', 'registration' => 'Pendaftaran', 'api' => 'API'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'unit_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function nextCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('code');
        $seq = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'EMP-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
