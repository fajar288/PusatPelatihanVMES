<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Course LMS (PRD §5.8): milik satu program (paket), opsional dikhususkan untuk satu kelas (jadwal). */
class Course extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'available_before_class' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, CourseModule::class, 'course_id', 'module_id');
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(DiscussionPost::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    /** Course yang berlaku untuk satu kelas: milik paketnya (umum) atau khusus kelas tersebut. */
    public function scopeForSchedule(Builder $q, TrainingSchedule $schedule): Builder
    {
        return $q->where('package_id', $schedule->package_id)
            ->where(fn (Builder $w) => $w->whereNull('schedule_id')->orWhere('schedule_id', $schedule->id))
            ->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Apakah peserta kelas boleh mengakses course hari ini (periode kelas + kebijakan akses).
     *
     * @return array{0:bool,1:?string} [boleh, alasan bila tidak]
     */
    public function accessWindow(TrainingSchedule $schedule): array
    {
        if (! $this->is_published) {
            return [false, 'Materi belum dipublikasikan.'];
        }
        if (! $this->available_before_class && $schedule->start_date->isFuture()) {
            return [false, 'Materi dapat dibuka mulai '.$schedule->start_date->locale('id')->translatedFormat('j F Y').'.'];
        }
        if ($this->access_days_after !== null && $schedule->end_date->copy()->addDays($this->access_days_after)->isPast()) {
            return [false, 'Masa akses materi untuk kelas ini sudah berakhir.'];
        }

        return [true, null];
    }
}
