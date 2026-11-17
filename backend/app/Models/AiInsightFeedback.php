<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Umpan balik pengguna atas satu insight AI ("membantu" / "tidak membantu"). Satu baris per pengguna per insight. */
class AiInsightFeedback extends Model
{
    protected $table = 'ai_insight_feedback';

    protected $fillable = ['ai_insight_id', 'user_id', 'helpful', 'note'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }

    /** @return BelongsTo<AiInsight, $this> */
    public function insight(): BelongsTo
    {
        return $this->belongsTo(AiInsight::class, 'ai_insight_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
