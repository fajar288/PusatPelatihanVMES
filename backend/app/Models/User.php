<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Peran dasar (PRD §5.6). `role` = peran utama; `user_roles` untuk multi-peran. */
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

    public const ROLES = [
        self::ROLE_SUPER_ADMIN, self::ROLE_ADMINISTRATOR, self::ROLE_MANAGEMENT, self::ROLE_MARKETING, self::ROLE_FINANCE,
        self::ROLE_TRAINING_COORDINATOR, self::ROLE_TRAINER, self::ROLE_ASSESSOR, self::ROLE_STUDENT, self::ROLE_ALUMNI,
    ];

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

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('created_at');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class)->latest();
    }

    /** Semua slug peran: peran utama + user_roles. @return string[] */
    public function roleSlugs(): array
    {
        return array_values(array_unique(array_filter([$this->role, ...$this->roles->pluck('slug')->all()])));
    }

    public function hasRole(string ...$slugs): bool
    {
        return count(array_intersect($slugs, $this->roleSlugs())) > 0;
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        $roleSlugs = $this->roleSlugs();

        return Permission::query()->where('slug', $slug)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', $roleSlugs))->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(...self::ADMIN_ROLES);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(...self::ADMIN_ROLES);
    }

    /** Tautan reset sandi mengarah ke frontend Next.js (FRONTEND_URL/reset-password?token=&email=). */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
