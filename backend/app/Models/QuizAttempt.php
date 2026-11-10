<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Percobaan kuis seorang peserta (enrollment). Dinilai otomatis saat submit. */
class QuizAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'submitted_at' => 'datetime', 'answers' => 'array', 'detail' => 'array', 'score' => 'decimal:2', 'max_score' => 'decimal:2', 'passed' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null;
    }
}
