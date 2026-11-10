<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_official' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeOfficial(Builder $q): Builder
    {
        return $q->active()->where('is_official', true);
    }
}
