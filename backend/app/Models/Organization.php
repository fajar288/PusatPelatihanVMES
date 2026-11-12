<?php

namespace App\Models;

use App\Support\Enterprise\OrganizationName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun perusahaan/instansi (PRD Roadmap 5 §4.1, §20.1). Tidak "memiliki" Member: hubungan dengan peserta lewat
 * EmploymentRelationship, dan akses data lewat enrollment yang disponsori (§18, §20.2).
 */
class Organization extends Model
{
    public const STATUSES = ['active' => 'Aktif', 'inactive' => 'Tidak aktif', 'suspended' => 'Ditangguhkan'];

    /**
     * Kebijakan akses corporate untuk enrollment bersponsor milik peserta yang employment-nya sudah INACTIVE
     * (keputusan terbuka, default PRD §20.7):
     * - summary: tidak tampil di daftar karyawan aktif & detail progres tertutup; tetap dihitung di laporan agregat
     *   dan status sertifikat tetap terlihat (bawaan).
     * - full: detail progres tetap bisa dibuka.
     * - none: hanya dihitung di laporan agregat (nama & status sertifikat tidak terlihat).
     */
    public const EXIT_ACCESS = [
        'summary' => 'Ringkasan saja (bawaan): agregat + status sertifikat',
        'full' => 'Penuh: detail progres tetap bisa dibuka',
        'none' => 'Tertutup: hanya dihitung di laporan agregat',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'needs_review' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Organization $o): void {
            $o->normalized_name = OrganizationName::normalize($o->legal_name) ?: (string) $o->code;
        });
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public function employments(): HasMany
    {
        return $this->hasMany(EmploymentRelationship::class);
    }

    public function corporateUsers(): HasMany
    {
        return $this->hasMany(CorporateUser::class);
    }

    public function sponsoredEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'sponsor_organization_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function name(): string
    {
        return $this->display_name ?: $this->legal_name;
    }

    /** Approval internal perusahaan sebelum penawaran disetujui (PRD Roadmap 5 §4.1 "Optional approval", §20.13). */
    public function requiresApproval(): bool
    {
        return (bool) ($this->settings['requires_approval'] ?? false);
    }

    public function trainingRequests(): HasMany
    {
        return $this->hasMany(TrainingRequest::class);
    }

    public function exitAccess(): string
    {
        $v = $this->settings['exit_access'] ?? 'summary';

        return array_key_exists($v, self::EXIT_ACCESS) ? $v : 'summary';
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public static function nextCode(): string
    {
        $last = static::query()->where('code', 'like', 'ORG-%')->orderByDesc('id')->value('code');
        $seq = $last ? ((int) substr($last, 4)) + 1 : 1;
        while (static::query()->where('code', $code = 'ORG-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT))->exists()) {
            $seq++;
        }

        return $code;
    }
}
