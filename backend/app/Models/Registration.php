<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

/** Pendaftaran training (PRD §5.5): individu atau kolektif perusahaan. Notifiable → email ke pendaftar (tamu maupun member). */
class Registration extends Model
{
    use Notifiable, SoftDeletes;

    /** @return array{0:string,1:string} */
    public function routeNotificationForMail(): array
    {
        return [$this->email => $this->name];
    }

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_CORPORATE = 'corporate';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'documents' => 'array',
            'consent' => 'boolean',
            'price_quoted' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    /** Organisasi sponsor untuk pendaftaran kolektif (Roadmap 5). Kolom `company` hanya isian, bukan hak akses. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RegistrationParticipant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest();
    }

    public function getRouteKeyName(): string
    {
        return 'registration_number';
    }

    public function scopeOwnedBy(Builder $q, User $user): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('user_id', $user->id)->orWhere('email', $user->email));
    }

    /** Nomor registrasi REG-YYYYMM-0001 (urut per bulan). */
    public static function nextNumber(): string
    {
        $prefix = 'REG-'.now()->format('Ym').'-';
        $last = static::withTrashed()->where('registration_number', 'like', $prefix.'%')->orderByDesc('registration_number')->value('registration_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
