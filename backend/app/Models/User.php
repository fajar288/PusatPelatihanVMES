<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Peran dasar (PRD §5.6). Tabel roles/permissions penuh menyusul di Phase 1E. */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_MANAGEMENT = 'management';

    public const ROLE_MARKETING = 'marketing';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_TRAINING_COORDINATOR = 'training_coordinator';

    public const ROLE_TRAINER = 'trainer';

    public const ROLE_ASSESSOR = 'assessor';

    public const ROLE_STUDENT = 'student';

    public const ROLE_ALUMNI = 'alumni';

    /** Peran yang boleh masuk panel admin (/admin). */
    public const ADMIN_ROLES = [
        self::ROLE_SUPER_ADMIN, self::ROLE_ADMINISTRATOR, self::ROLE_MANAGEMENT, self::ROLE_MARKETING,
        self::ROLE_FINANCE, self::ROLE_TRAINING_COORDINATOR,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, self::ADMIN_ROLES, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }
}
