<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['show_on_home' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(TrainingPackage::class, 'category_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeOnHome(Builder $q): Builder
    {
        return $q->active()->where('show_on_home', true);
    }
}
