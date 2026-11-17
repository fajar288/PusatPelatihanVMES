<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Label manual admin untuk satu komentar survei (pembanding akurasi klasifikasi AI). */
class FeedbackLabel extends Model
{
    protected $fillable = ['response_id', 'question_key', 'sentiment', 'category', 'theme', 'actionable', 'labeled_by'];

    protected function casts(): array
    {
        return ['actionable' => 'boolean'];
    }

    /** @return BelongsTo<SurveyResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'response_id');
    }
}
