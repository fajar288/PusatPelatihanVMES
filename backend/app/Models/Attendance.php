<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    public const LABELS = ['present' => 'Hadir', 'absent' => 'Tidak hadir', 'late' => 'Terlambat', 'excused' => 'Izin'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
