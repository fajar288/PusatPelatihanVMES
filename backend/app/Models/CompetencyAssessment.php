<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Penilaian kompetensi individual per enrollment berdasarkan rubrik program (PRD §5.9). */
class CompetencyAssessment extends Model
{
    public const STATUSES = ['draft' => 'Draf', 'validated' => 'Tervalidasi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total_percent' => 'decimal:2', 'is_competent' => 'boolean', 'assessed_at' => 'datetime', 'validated_at' => 'datetime', 'is_locked' => 'boolean'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(AssessmentRubric::class, 'rubric_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class, 'assessment_id');
    }

    public function isValidated(): bool
    {
        return $this->status === 'validated';
    }

    /** Hitung ulang total (rata-rata tertimbang skor/maks × 100) & status kompeten dari skor yang terisi. */
    public function recalculate(): void
    {
        $this->load('scores')->loadMissing('rubric.criteria');
        $sumW = 0.0;
        $sum = 0.0;
        foreach ($this->rubric->criteria as $c) {
            $s = $this->scores->firstWhere('criterion_id', $c->id);
            if ($s === null || $s->score === null || (int) $c->max_score === 0) {
                continue;
            }
            $w = (float) $c->weight;
            $sumW += $w;
            $sum += min(1, (float) $s->score / (int) $c->max_score) * $w;
        }
        $this->total_percent = $sumW > 0 ? round($sum / $sumW * 100, 2) : null;
        $this->is_competent = $this->total_percent === null ? null : $this->total_percent >= $this->rubric->competent_threshold;
    }
}
