<?php

namespace App\Http\Resources;

use App\Models\TrainingPackage;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bentuk identik dengan tipe `Course` di frontend (src/data/courses.ts). @mixin TrainingPackage */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$st, $label] = $this->availabilityPill();

        return [
            'slug' => $this->slug,
            'code' => $this->code,
            'title' => $this->title,
            'category' => $this->category?->name,
            'categorySlug' => $this->category?->slug,
            'vendor' => $this->vendor,
            'desc' => $this->excerpt,
            'durationDays' => $this->duration_days,
            'durationHours' => $this->duration_hours,
            'mode' => $this->modeLabel(),
            'status' => $st,
            'statusLabel' => $label,
            'price' => $this->price !== null ? (float) $this->price : null,
            'popular' => $this->is_popular,
            'image' => Media::url($this->image),
            'publishedAt' => $this->published_at?->timestamp,
            'level' => $this->level,
            'certificate' => $this->certificate,
            'programType' => $this->program_type,
            'programTypeLabel' => TrainingPackage::PROGRAM_TYPES[$this->program_type] ?? $this->program_type,
            'certificateType' => $this->certificate_type,
            'completionRule' => $this->completionRule(),
            'overview' => [
                'intro' => $this->overview_intro ?? [],
                'learn' => $this->learning_outcomes ?? [],
                'audience' => $this->audience ?? [],
                'requirements' => $this->requirements ?? [],
            ],
            'curricula' => $this->whenLoaded('curricula', fn () => $this->curricula->map(fn ($c) => [
                'title' => $c->title, 'topics' => $c->topics ?? [], 'durationHours' => $c->duration_hours, 'method' => $c->method, 'outcomes' => $c->outcomes,
            ])),
            'schedules' => ScheduleResource::collection($this->whenLoaded('schedules')),
            'related' => PackageResource::collection($this->whenLoaded('related')),
            'seo' => ['title' => $this->seo_title, 'description' => $this->seo_description],
        ];
    }
}
