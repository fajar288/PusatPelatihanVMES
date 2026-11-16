<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Level kompetensi yang dibutuhkan untuk satu posisi di satu organisasi (PRD Roadmap 5 §20.16 "Required"). */
class CompetencyTarget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['required_level' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (CompetencyTarget $t): void {
            $t->position = trim(preg_replace('/\s+/u', ' ', (string) $t->position) ?? '');
            $t->position_key = self::key($t->position);
        });
    }

    /** Kunci posisi untuk pencocokan dengan employment_relationships.position: huruf kecil, spasi tunggal. */
    public static function key(?string $position): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $position) ?? ''));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
