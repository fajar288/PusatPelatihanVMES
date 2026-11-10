<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Testimonial extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_approved' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('is_approved', true)->orderByDesc('is_featured')->orderBy('sort_order');
    }

    /** "Jabatan • Perusahaan • Kota" (format website lama). */
    public function roleLabel(): string
    {
        return implode(' • ', array_filter([$this->position, $this->company, $this->city]));
    }
}
