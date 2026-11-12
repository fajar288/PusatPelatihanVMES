<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * Undangan peserta dari perusahaan (PRD Roadmap 5 §4.3, §20.4): karyawan menerima tautan, masuk/daftar dengan email
 * yang diundang (sekaligus memverifikasi email), memberi consent, lalu ditetapkan ke kursi. Token disimpan sebagai hash.
 */
class ParticipantInvitation extends Model
{
    use Notifiable;

    public const STATUSES = ['pending' => 'Menunggu', 'accepted' => 'Diterima', 'revoked' => 'Dibatalkan', 'expired' => 'Kedaluwarsa'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'consent_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TrainingRequest::class, 'training_request_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(RequestSeat::class, 'request_seat_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'unit_id');
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
