<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function scopeLive(Builder $q, string $position = 'hero'): Builder
    {
        return $q->where('position', $position)->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order');
    }
}
