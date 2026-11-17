<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Impresi atau klik satu rekomendasi training (lihat migrasi ai_recommendation_events). */
class AiRecommendationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<AiInsight, $this> */
    public function insight(): BelongsTo
    {
        return $this->belongsTo(AiInsight::class, 'ai_insight_id');
    }

    /** @return BelongsTo<TrainingPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class);
    }
}
