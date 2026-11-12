<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengguna perusahaan: User ↔ Organization + peran (PRD Roadmap 5 §20.12) + batas unit opsional (§20.14). */
class CorporateUser extends Model
{
    public const ROLES = [
        'owner' => 'Corporate Owner',
        'hr_admin' => 'HR/L&D Admin',
        'supervisor' => 'Supervisor',
        'finance' => 'Finance',
        'viewer' => 'Viewer',
    ];

    /** Kemampuan per peran perusahaan (dipakai policy; portal 5B memakai daftar yang sama). */
    public const CAPABILITIES = [
        'owner' => ['view_progress', 'manage_participants', 'manage_requests', 'approve_requests', 'manage_users', 'view_finance', 'export'],
        'hr_admin' => ['view_progress', 'manage_participants', 'manage_requests', 'export'],
        'supervisor' => ['view_progress'],
        'finance' => ['view_finance'],
        'viewer' => ['view_progress'],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['disabled_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function allows(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES[$this->role] ?? [], true);
    }

    /** Unit yang boleh dilihat (null = seluruh organisasi). @return list<int>|null */
    public function unitScope(): ?array
    {
        return $this->unit_id ? $this->unit?->selfAndDescendantIds() : null;
    }
}
