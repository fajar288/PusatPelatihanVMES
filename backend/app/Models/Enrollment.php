<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Peserta resmi satu kelas (hasil konversi pendaftar → member → enrollment, PRD §5.5). */
class Enrollment extends Model
{
    public const STATUSES = ['enrolled', 'attending', 'completed', 'failed', 'dropped'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime', 'completed_at' => 'datetime', 'final_score' => 'decimal:2'];
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

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Persentase kehadiran (hadir/terlambat dihitung hadir) terhadap sesi yang sudah lewat. */
    public function attendancePercent(): ?int
    {
        $sessions = $this->schedule?->sessions()->whereDate('date', '<=', today())->count() ?? 0;
        if ($sessions === 0) {
            return null;
        }
        $present = $this->attendances()->whereIn('status', ['present', 'late'])->count();

        return (int) round($present / $sessions * 100);
    }
}
