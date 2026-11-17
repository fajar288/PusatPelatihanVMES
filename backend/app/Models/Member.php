<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Identitas digital peserta (PRD §5.6): satu akun = satu member, nomor member tetap. */
class Member extends Model
{
    public const STATUSES = ['registered', 'active', 'alumni', 'inactive'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'alumni_since' => 'date', 'communication_pref' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class)->latest();
    }

    /** Sertifikat milik member (semua status). */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** Invoice dari pendaftaran member (invoice permintaan training perusahaan tidak termasuk). */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, Registration::class, 'member_id', 'registration_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** Hubungan kerja (Roadmap 5 §20.6): satu member bisa punya banyak, aktif maupun tidak. */
    public function employments(): HasMany
    {
        return $this->hasMany(EmploymentRelationship::class)->latest('id');
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
