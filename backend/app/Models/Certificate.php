<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Sertifikat per peserta/enrollment (PRD §5.10).
 * Status: draft → pending_validation → issued → revoked | replaced. Nomor unik ditetapkan saat dibuat.
 */
class Certificate extends Model
{
    public const STATUSES = [
        'draft' => 'Draf', 'pending_validation' => 'Menunggu validasi', 'issued' => 'Terbit', 'revoked' => 'Dicabut', 'replaced' => 'Diganti',
    ];

    public const TYPES = CertificateTemplate::TYPES;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'issued_at' => 'date', 'valid_until' => 'date',
            'score' => 'decimal:2', 'competencies' => 'array', 'hours' => 'integer',
            'pdf_generated_at' => 'datetime', 'submitted_at' => 'datetime', 'issued_on' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class, 'package_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** Riwayat penerbitan (audit log). */
    public function history(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function scopeActiveForEnrollment(Builder $q, int $enrollmentId): Builder
    {
        return $q->where('enrollment_id', $enrollmentId)->whereIn('status', ['draft', 'pending_validation', 'issued']);
    }

    /** Sertifikat milik akun: lewat member atau email penerima. */
    public function scopeOwnedBy(Builder $q, User $user): Builder
    {
        $enrollmentIds = Enrollment::query()->ownedBy($user)->select('id');

        return $q->where(fn (Builder $w) => $w->whereIn('enrollment_id', $enrollmentIds)
            ->orWhere('member_id', $user->member?->id ?? 0));
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    /** Masih berlaku: terbit dan belum lewat masa berlaku. */
    public function isValid(): bool
    {
        return $this->isIssued() && ($this->valid_until === null || $this->valid_until->endOfDay()->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->isIssued() && $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** Boleh diubah datanya (belum terbit). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'pending_validation'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** URL halaman verifikasi publik (frontend). */
    public function verificationUrl(): string
    {
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');

        return $base.'/verifikasi/'.rawurlencode($this->number).'?k='.$this->verification_code;
    }

    /**
     * Nomor berikutnya sesuai format template: {TYPE} {YYYY} {MM} {SEQ} (urut per jenis per tahun, 5 digit).
     */
    public static function nextNumber(CertificateTemplate $template, string $type, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        $code = CertificateTemplate::TYPE_CODES[$type] ?? strtoupper(substr($type, 0, 3));
        $format = $template->number_format ?: 'PPTI-{TYPE}-{YYYY}{MM}-{SEQ}';
        $base = strtr($format, ['{TYPE}' => $code, '{YYYY}' => $date->format('Y'), '{YY}' => $date->format('y'), '{MM}' => $date->format('m')]);
        // urutan per jenis + tahun, terlepas dari bulan/format
        $yearPrefix = strtr(Str::before($format, '{SEQ}'), ['{TYPE}' => $code, '{YYYY}' => $date->format('Y'), '{YY}' => $date->format('y'), '{MM}' => '']);
        $like = str_contains($format, '{MM}') ? strtr(Str::before($format, '{MM}'), ['{TYPE}' => $code, '{YYYY}' => $date->format('Y'), '{YY}' => $date->format('y')]).'%' : $yearPrefix.'%';
        $seq = (int) static::query()->where('type', $type)->where('number', 'like', $like)
            ->get(['number'])->map(fn ($c) => (int) preg_replace('/\D/', '', Str::afterLast($c->number, '-')))->max() + 1;
        do {
            $number = str_replace('{SEQ}', str_pad((string) $seq, 5, '0', STR_PAD_LEFT), $base);
            $seq++;
        } while (static::query()->where('number', $number)->exists());

        return $number;
    }

    public static function newVerificationCode(): string
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (static::query()->where('verification_code', $code)->exists());

        return $code;
    }
}
