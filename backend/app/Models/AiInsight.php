<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan keluaran AI untuk tata kelola PRD Roadmap 5 §9.1. Tahap 5G: hanya struktur, belum ada penulis.
 * `data_reference` berisi ID dan hash data sumber, tidak pernah salinan data pribadi.
 *
 * @property string $use_case
 * @property string|null $model_version
 * @property string|null $prompt_version
 * @property array<string, mixed>|null $data_reference
 */
class AiInsight extends Model
{
    protected $fillable = ['use_case', 'model_version', 'prompt_version', 'data_reference', 'reviewer_id', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'data_reference' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
