<?php

namespace App\Models;

use App\Services\MemberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function logbookEntries(): HasMany
    {
        return $this->hasMany(LogbookEntry::class);
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(CompetencyAssessment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class)->latest('id');
    }

    /** Sertifikat aktif (bukan dicabut/diganti) — satu per enrollment. */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class)->whereIn('status', ['draft', 'pending_validation', 'issued'])->latestOfMany();
    }

    /** Status member mengikuti enrollment (registered → active → alumni), lihat MemberService::syncStatus. */
    protected static function booted(): void
    {
        static::saved(function (Enrollment $e): void {
            if ($e->member_id && ($e->wasRecentlyCreated || $e->wasChanged(['status', 'member_id']))) {
                $member = $e->member;
                if ($member) {
                    app(MemberService::class)->syncStatus($member);
                }
            }
        });
    }

    /** Enrollment milik akun: lewat pendaftarannya, member-nya, atau email peserta (kolektif). */
    public function scopeOwnedBy(Builder $q, User $user): Builder
    {
        $regIds = Registration::query()->ownedBy($user)->select('id');

        return $q->where(fn (Builder $w) => $w->whereIn('registration_id', $regIds)
            ->orWhere('member_id', $user->member?->id ?? 0)
            ->orWhere('participant_email', $user->email));
    }

    /** Status yang masih boleh mengakses LMS. */
    public function canLearn(): bool
    {
        return in_array($this->status, ['enrolled', 'attending', 'completed'], true);
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
