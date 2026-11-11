<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingSchedule extends Model
{
    use SoftDeletes;

    /** Status kelas sesuai PRD §5.4. */
    public const STATUSES = [
        'draft', 'registration_open', 'registration_closed', 'confirmed', 'ongoing', 'completed', 'cancelled',
    ];

    /** Status yang tampil di website publik. */
    public const PUBLIC_STATUSES = ['registration_open', 'registration_closed', 'confirmed', 'ongoing'];

    protected $guarded = [];

    /** Alasan perubahan (diisi form admin, tidak disimpan di tabel ini; dicatat TrainingScheduleObserver). */
    public ?string $changeReason = null;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_deadline' => 'date',
            'price' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(Trainer::class, 'class_trainers', 'schedule_id', 'trainer_id')->withPivot('role')->withTimestamps();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'schedule_id')->orderBy('session_no');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'schedule_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'schedule_id');
    }

    public function waitlists(): HasMany
    {
        return $this->hasMany(Waitlist::class, 'schedule_id')->where('status', 'waiting');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ScheduleChange::class, 'schedule_id')->latest();
    }

    public function logbooks(): HasMany
    {
        return $this->hasMany(TrainingLogbook::class, 'schedule_id')->orderBy('date')->orderBy('id');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->whereIn('status', self::PUBLIC_STATUSES)
            ->where(fn (Builder $w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->published()->whereDate('end_date', '>=', today())->orderBy('start_date');
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function remaining(): int
    {
        return max(0, (int) $this->quota - (int) $this->registered_count);
    }

    public function durationDays(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function effectivePrice(): ?float
    {
        return $this->price !== null ? (float) $this->price : ($this->package?->price !== null ? (float) $this->package->price : null);
    }

    public function timeLabel(): ?string
    {
        if (! $this->start_time) {
            return null;
        }
        $f = fn (string $t) => str_replace(':', '.', substr($t, 0, 5));

        return $f($this->start_time).($this->end_time ? ' - '.$f($this->end_time) : '').' WIB';
    }

    /** @return array{0:string,1:string} [jst ok|wn|no, label] — identik dengan pill jadwal website lama. */
    public function statusPill(): array
    {
        return match ($this->status) {
            'cancelled' => ['no', 'Dibatalkan'],
            'completed' => ['no', 'Selesai'],
            'ongoing' => ['no', 'Berlangsung'],
            'registration_closed' => $this->remaining() === 0 ? ['no', 'Penuh'] : ['no', 'Pendaftaran ditutup'],
            default => $this->quota === null || $this->remaining() > 3
                ? ['ok', 'Tersedia']
                : ($this->remaining() === 0 ? ['no', 'Penuh'] : ['wn', 'Terbatas']),
        };
    }
}
