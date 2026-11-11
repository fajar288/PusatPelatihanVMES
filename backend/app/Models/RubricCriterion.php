<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kriteria/aspek dalam rubrik penilaian (bobot, skala, deskriptor level). */
class RubricCriterion extends Model
{
    public const CODES = ['theory' => 'Pemahaman teori', 'practice' => 'Praktik', 'troubleshooting' => 'Troubleshooting', 'tools' => 'Penggunaan alat', 'assignment' => 'Penyelesaian tugas', 'independence' => 'Kemandirian', 'custom' => 'Lainnya'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['levels' => 'array', 'weight' => 'decimal:2'];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(AssessmentRubric::class, 'rubric_id');
    }

    /** Label level untuk skor tertentu (bila deskriptor diisi). */
    public function levelLabel(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }
        foreach ($this->levels ?? [] as $lv) {
            if ((float) ($lv['score'] ?? -1) === (float) $score) {
                return $lv['label'] ?? null;
            }
        }

        return null;
    }
}
