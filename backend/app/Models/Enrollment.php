<?php

namespace App\Models;

use App\Services\MemberService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Peserta resmi satu kelas (hasil konversi pendaftar → member → enrollment, PRD §5.5).
 * Roadmap 5: enrollment adalah unit sponsorship & visibilitas perusahaan (§4.4, §18) —
 * CORPORATE wajib punya sponsor organisasi (R1), SELF tanpa sponsor (R2), sponsor lama hanya berubah lewat
 * SponsorshipService (R7/R8). Aturan ini juga dijaga constraint DB `enrollments_sponsor_rule`.
 */
class Enrollment extends Model
{
    public const STATUSES = ['enrolled', 'attending', 'completed', 'failed', 'dropped'];

    public const TYPE_SELF = 'self';

    public const TYPE_CORPORATE = 'corporate';

    public const TYPES = [self::TYPE_SELF => 'Mandiri (self-funded)', self::TYPE_CORPORATE => 'Corporate (disponsori)'];

    /** Diaktifkan sementara oleh SponsorshipService saat menerapkan transfer resmi. */
    public static bool $sponsorChangeAuthorized = false;

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

    public function registrationParticipant(): BelongsTo
    {
        return $this->belongsTo(RegistrationParticipant::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sponsor_organization_id');
    }

    public function sponsorUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'sponsor_unit_id');
    }

    /** Kursi Corporate Training Request yang menghasilkan enrollment ini (bila ada). */
    public function requestSeat(): HasOne
    {
        return $this->hasOne(RequestSeat::class, 'enrollment_id');
    }

    public function sponsorshipTransfers(): HasMany
    {
        return $this->hasMany(SponsorshipTransfer::class)->latest('id');
    }

    public function isCorporate(): bool
    {
        return $this->type === self::TYPE_CORPORATE;
    }

    /** R3: hanya enrollment CORPORATE dengan sponsor organisasi yang sama. */
    public function scopeVisibleToOrganization(Builder $q, Organization|int $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $q->where('type', self::TYPE_CORPORATE)->where('sponsor_organization_id', $id);
    }

    /**
     * Batas data untuk pengguna perusahaan (server-side, §20.21): sponsor organisasi + batas unit pengguna.
     * Kebijakan exit_access diterapkan di CorporateAccess (daftar/detail), bukan di sini — agregat tetap menghitung semua.
     */
    public function scopeVisibleToCorporateUser(Builder $q, CorporateUser $cu): Builder
    {
        $q->visibleToOrganization($cu->organization_id);
        if (($units = $cu->unitScope()) !== null) {
            $q->whereIn('sponsor_unit_id', $units);
        }

        return $q;
    }

    public function scopeSelfFunded(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_SELF);
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
        static::saving(function (Enrollment $e): void {
            $e->type ??= self::TYPE_SELF;
            if (! array_key_exists($e->type, self::TYPES)) {
                throw new DomainException("Tipe enrollment tidak dikenal: {$e->type}");
            }
            if ($e->type === self::TYPE_CORPORATE && ! $e->sponsor_organization_id) {
                throw new DomainException('R1: enrollment CORPORATE wajib memiliki sponsor organisasi.');
            }
            if ($e->type === self::TYPE_SELF && ($e->sponsor_organization_id || $e->sponsor_unit_id)) {
                throw new DomainException('R2: enrollment SELF tidak boleh memiliki sponsor organisasi.');
            }
            if ($e->exists && $e->isDirty(['type', 'sponsor_organization_id']) && ! self::$sponsorChangeAuthorized) {
                throw new DomainException('R7/R8: sponsor enrollment hanya bisa diubah lewat transfer sponsor resmi.');
            }
        });

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
