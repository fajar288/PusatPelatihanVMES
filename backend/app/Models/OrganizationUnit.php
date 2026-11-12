<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Departemen / cost center / proyek dalam organisasi (PRD Roadmap 5 §4.1 Cost center, §20.14). */
class OrganizationUnit extends Model
{
    public const TYPES = ['department' => 'Departemen', 'cost_center' => 'Cost center', 'project' => 'Proyek'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** ID unit ini beserta seluruh turunannya (untuk batas akses supervisor). @return list<int> */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    public function label(): string
    {
        return ($this->code ? $this->code.' · ' : '').$this->name;
    }
}
