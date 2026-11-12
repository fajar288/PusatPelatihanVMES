<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Antrean tinjauan identitas peserta (PRD Roadmap 5 §20.5): data meragukan tidak otomatis membuat akun. */
class IdentityReview extends Model
{
    public const REASONS = [
        'not_found' => 'Tidak ditemukan — perlu undangan/akun baru',
        'phone_match' => 'Nomor HP cocok — perlu konfirmasi',
        'unverified_email' => 'Email cocok tetapi belum terverifikasi',
        'employee_id_match' => 'Employee ID cocok di organisasi',
        'conflict' => 'Data menunjuk ke beberapa member berbeda',
        'incomplete' => 'Data tidak lengkap (tanpa email/HP/nomor member)',
        'unknown_member_number' => 'Nomor member tidak ditemukan',
    ];

    public const STATUSES = ['pending' => 'Menunggu', 'resolved' => 'Selesai', 'dismissed' => 'Diabaikan'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input' => 'array', 'candidates' => 'array', 'resolved_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TrainingSchedule::class, 'schedule_id');
    }

    public function resolvedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'resolved_member_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }
}
