<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Learning progress per lesson per enrollment (dibuka, video %, selesai). Terpisah dari kehadiran & logbook (PRD §12). */
class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'completed_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
