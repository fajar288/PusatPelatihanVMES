<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Profil trainer/assessor (PRD §5.7). */
class Trainer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expertise' => 'array', 'certifications' => 'array', 'is_assessor' => 'boolean', 'is_active' => 'boolean', 'show_on_site' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(TrainingSchedule::class, 'class_trainers', 'trainer_id', 'schedule_id')->withPivot('role')->withTimestamps();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** Inisial untuk avatar (mis. "AP"). */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2))));
    }
}
