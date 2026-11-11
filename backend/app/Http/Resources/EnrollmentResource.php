<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Models\Enrollment;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Enrollment untuk portal member ("Pelatihan Saya"): kelas, sesi, kehadiran. @mixin Enrollment */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->schedule;
        $sessions = $s?->sessions ?? collect();
        $att = $this->attendances->keyBy('session_id');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'participantName' => $this->participant_name,
            'registration' => $this->registration?->registration_number,
            'enrolledAt' => $this->enrolled_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'finalScore' => $this->final_score !== null ? (float) $this->final_score : null,
            'attendancePercent' => $this->attendancePercent(),
            'certificate' => $this->relationLoaded('certificate') && $this->certificate && $this->certificate->status !== 'draft' && $this->certificate->status !== 'pending_validation' ? [
                'number' => $this->certificate->number, 'status' => $this->certificate->status, 'statusLabel' => $this->certificate->statusLabel(),
                'typeLabel' => $this->certificate->typeLabel(), 'issuedLabel' => $this->certificate->issued_at ? IdDate::long($this->certificate->issued_at) : null,
                'downloadable' => $this->certificate->isIssued(), 'verificationUrl' => $this->certificate->verificationUrl(),
            ] : null,
            'schedule' => $s ? [
                'code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'packageSlug' => $s->package?->slug,
                'startDate' => $s->start_date->toDateString(), 'endDate' => $s->end_date->toDateString(), 'dateLabel' => IdDate::long($s->start_date),
                'city' => $s->city, 'location' => $s->location, 'mode' => $s->mode, 'time' => $s->timeLabel(), 'classStatus' => $s->status,
                'trainers' => $s->trainers->map(fn ($t) => ['name' => $t->name, 'role' => $t->pivot->role])->values(),
            ] : null,
            'sessions' => $sessions->map(fn ($se) => [
                'no' => $se->session_no, 'date' => $se->date->toDateString(), 'dateLabel' => IdDate::short($se->date), 'topic' => $se->topic,
                'time' => $se->start_time ? substr($se->start_time, 0, 5).($se->end_time ? ' - '.substr($se->end_time, 0, 5) : '') : null,
                'attendance' => $att->get($se->id)?->status, 'attendanceLabel' => Attendance::LABELS[$att->get($se->id)?->status ?? ''] ?? null,
            ])->values(),
        ];
    }
}
