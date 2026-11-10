<?php

namespace App\Http\Resources;

use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bentuk identik dengan tipe `Schedule` di frontend (src/data/schedules.ts); `id` = kode kelas. @mixin TrainingSchedule */
class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$st, $label] = $this->statusPill();
        $pkg = $this->package;
        $title = $this->title ?? $pkg?->title;

        return [
            'id' => $this->code,
            'code' => $this->code,
            'courseSlug' => $pkg?->slug,
            'title' => $title,
            'batchTitle' => $title.' - '.IdDate::batch($this->start_date),
            'category' => $pkg?->category?->name,
            'city' => $this->city,
            'location' => $this->location,
            'mode' => TrainingPackage::MODE_LABELS[$this->mode] ?? $this->mode,
            'dateLabel' => IdDate::long($this->start_date),
            'endDateLabel' => IdDate::long($this->end_date),
            'shortDate' => IdDate::short($this->start_date),
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            'time' => $this->timeLabel(),
            'durationDays' => $this->durationDays(),
            'quota' => $this->quota,
            'remaining' => $this->remaining(),
            'trainer' => $this->trainer_name,
            'status' => $st,
            'statusLabel' => $label,
            'classStatus' => $this->status,
            'price' => $this->effectivePrice(),
            'publishedAt' => $this->published_at?->timestamp,
            'certificate' => $pkg?->certificate,
        ];
    }
}
