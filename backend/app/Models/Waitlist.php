<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Daftar tunggu kelas penuh (PRD §5.4). */
class Waitlist extends Model
{
    public const STATUSES = ['waiting', 'offered', 'converted', 'cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['offered_at' => 'datetime'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
