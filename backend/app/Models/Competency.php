<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master kompetensi (PRD Roadmap 5 §13 "Competency", §20.16). Bukti kompetensi berasal dari kriteria rubrik yang
 * ditautkan (assessment) dan program yang membangun kompetensi ini (training). Lihat CompetencyGapService.
 */
class Competency extends Model
{
    protected $guarded = [];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Competency $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    /** Program yang membangun kompetensi ini + level yang dicapai lulusannya. */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(TrainingPackage::class, 'package_competencies', 'competency_id', 'package_id')
            ->withPivot(['id', 'level'])->withTimestamps();
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CompetencyTarget::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
