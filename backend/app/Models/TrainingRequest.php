<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permintaan training perusahaan (PRD Roadmap 5 §4.2, §20.3, §20.13). Dibuat dari kebutuhan + jumlah kursi,
 * peserta diisi kemudian. Status: DRAFT → SUBMITTED → QUOTED → (APPROVAL) → APPROVED → PAID → SCHEDULED →
 * IN_PROGRESS → COMPLETED, atau CANCELLED.
 */
class TrainingRequest extends Model
{
    public const STATUSES = [
        'draft' => 'Draf',
        'submitted' => 'Dikirim ke VMES',
        'quoted' => 'Penawaran dibuat',
        'approval' => 'Menunggu persetujuan',
        'approved' => 'Disetujui',
        'paid' => 'Pembayaran diterima',
        'scheduled' => 'Terjadwal',
        'in_progress' => 'Berjalan',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    public const DELIVERY = ['onsite' => 'On-site (di lokasi perusahaan)', 'vmes' => 'Di VMES Training Center', 'online' => 'Online', 'hybrid' => 'Hybrid'];

    /** Status yang boleh menerima penugasan peserta (kursi bisa diisi kapan saja sebelum selesai/batal). */
    public const ASSIGNABLE = ['draft', 'submitted', 'quoted', 'approval', 'approved', 'paid', 'scheduled', 'in_progress'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_date' => 'date', 'quote_valid_until' => 'date', 'price_per_seat' => 'decimal:2', 'quote_discount' => 'decimal:2', 'quote_total' => 'decimal:2',
            'submitted_at' => 'datetime', 'quoted_at' => 'datetime', 'quote_accepted_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
            'scheduled_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TrainingPackage::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'unit_id');
    }

    public function requestSeats(): HasMany
    {
        return $this->hasMany(RequestSeat::class)->orderBy('seat_no');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ParticipantInvitation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isAssignable(): bool
    {
        return in_array($this->status, self::ASSIGNABLE, true);
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public static function nextCode(): string
    {
        $prefix = 'REQ-'.now()->format('Ym').'-';
        $last = static::query()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');

        return $prefix.str_pad((string) ($last ? ((int) substr($last, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
