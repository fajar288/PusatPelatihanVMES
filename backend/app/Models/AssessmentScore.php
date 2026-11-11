<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skor satu kriteria rubrik dalam penilaian kompetensi. */
class AssessmentScore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CompetencyAssessment::class, 'assessment_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'criterion_id');
    }
}
