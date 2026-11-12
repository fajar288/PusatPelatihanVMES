<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kursi dalam permintaan training (PRD Roadmap 5 §4.2 "Reserved seat tidak membuat participant palsu").
 * reserved → (invited) → assigned (member diketahui, menunggu jadwal) → enrolled (enrollment CORPORATE dibuat).
 */
class RequestSeat extends Model
{
    public const STATUSES = ['reserved' => 'Kosong (dipesan)', 'invited' => 'Undangan terkirim', 'assigned' => 'Peserta ditetapkan', 'enrolled' => 'Terdaftar di kelas'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TrainingRequest::class, 'training_request_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function employment(): BelongsTo
    {
        return $this->belongsTo(EmploymentRelationship::class, 'employment_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'unit_id');
    }

    public function pendingInvitation(): HasOne
    {
        return $this->hasOne(ParticipantInvitation::class)->where('status', 'pending')->latestOfMany();
    }

    public function isFree(): bool
    {
        return $this->status === 'reserved';
    }
}
