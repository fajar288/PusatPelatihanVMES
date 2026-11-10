<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengumpulan tugas peserta (satu per lesson per enrollment; kirim ulang menimpa bila dikembalikan). */
class AssignmentSubmission extends Model
{
    public const STATUSES = ['submitted' => 'Dikumpulkan', 'graded' => 'Dinilai', 'returned' => 'Dikembalikan (revisi)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'graded_at' => 'datetime', 'score' => 'decimal:2'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
