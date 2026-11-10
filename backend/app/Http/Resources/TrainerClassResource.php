<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Kelas untuk portal trainer: info kelas + (pada detail) peserta, sesi, matriks kehadiran. @mixin TrainingSchedule */
class TrainerClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('enrollments');
        $sessions = $this->sessions;

        return [
            'code' => $this->code,
            'title' => $this->title ?? $this->package?->title,
            'packageSlug' => $this->package?->slug,
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            'dateLabel' => IdDate::long($this->start_date),
            'endLabel' => IdDate::long($this->end_date),
            'time' => $this->timeLabel(),
            'city' => $this->city,
            'location' => $this->location,
            'mode' => $this->mode,
            'status' => $this->status,
            'quota' => $this->quota,
            'participantsCount' => $this->enrollments_count ?? $this->enrollments()->count(),
            'sessionsCount' => $sessions->count(),
            'myRole' => $this->trainers->firstWhere('user_id', $request->user()?->id)?->pivot?->role,
            'trainers' => $this->trainers->map(fn ($t) => ['name' => $t->name, 'role' => $t->pivot->role])->values(),
            'sessions' => $sessions->map(fn ($s) => [
                'no' => $s->session_no, 'date' => $s->date->toDateString(), 'dateLabel' => IdDate::short($s->date), 'topic' => $s->topic,
                'time' => $s->start_time ? substr($s->start_time, 0, 5).($s->end_time ? ' - '.substr($s->end_time, 0, 5) : '') : null,
                'marked' => $detail ? $s->attendances->count() : null,
            ])->values(),
            'participants' => $this->when($detail, fn () => $this->enrollments->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->participant_name, 'email' => $e->participant_email, 'status' => $e->status,
                'attendancePercent' => $e->attendancePercent(),
                'attendance' => $sessions->mapWithKeys(fn ($s) => [$s->session_no => $e->attendances->firstWhere('session_id', $s->id)?->status])->all(),
            ])->values()),
            'attendanceLabels' => $this->when($detail, Attendance::LABELS),
        ];
    }
}
