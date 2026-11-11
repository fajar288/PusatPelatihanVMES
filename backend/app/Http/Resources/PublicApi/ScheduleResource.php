<?php

namespace App\Http\Resources\PublicApi;

use App\Models\TrainingSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API Publik v1 — jadwal kelas. @mixin TrainingSchedule */
class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pkg = $this->package;

        return [
            'code' => $this->code,
            'title' => $this->title ?? $pkg?->title,
            'program' => $pkg ? ['slug' => $pkg->slug, 'code' => $pkg->code, 'title' => $pkg->title] : null,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'timezone' => 'Asia/Jakarta',
            'city' => $this->city,
            'location' => $this->location,
            'mode' => $this->mode,
            'status' => $this->status,
            'registration_open' => $this->acceptsRegistrations(),
            'registration_deadline' => $this->registration_deadline?->toDateString(),
            'quota' => $this->quota,
            'seats_remaining' => $this->quota !== null ? $this->remaining() : null,
            'price' => $this->effectivePrice(),
            'currency' => 'IDR',
            'trainer' => $this->trainer_name,
            'url' => rtrim((string) config('services.frontend.url'), '/').'/jadwal/'.$this->code,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
