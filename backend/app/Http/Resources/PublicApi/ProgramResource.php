<?php

namespace App\Http\Resources\PublicApi;

use App\Models\TrainingPackage;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API Publik v1 — program (paket training). Detail menambah silabus & jadwal mendatang. @mixin TrainingPackage */
class ProgramResource extends JsonResource
{
    public bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $base = [
            'code' => $this->code,
            'slug' => $this->slug,
            'title' => $this->title,
            'vendor' => $this->vendor,
            'category' => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null,
            'excerpt' => $this->excerpt,
            'program_type' => $this->program_type,
            'mode' => $this->mode,
            'level' => $this->level,
            'duration_days' => $this->duration_days,
            'duration_hours' => $this->duration_hours,
            'price' => $this->price !== null ? (float) $this->price : null,
            'currency' => 'IDR',
            'certificate_type' => $this->certificate_type,
            'certificate' => $this->certificate,
            'availability' => $this->availability,
            'is_popular' => (bool) $this->is_popular,
            'image_url' => Media::url($this->image),
            'url' => rtrim((string) config('services.frontend.url'), '/').'/kursus/'.$this->slug,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $this->detail) {
            return $base;
        }

        return $base + [
            'objectives' => $this->objectives,
            'competencies' => $this->competencies ?? [],
            'learning_outcomes' => $this->learning_outcomes ?? [],
            'audience' => $this->audience ?? [],
            'requirements' => $this->requirements ?? [],
            'curriculum' => $this->curricula->sortBy('sort_order')->values()->map(fn ($c) => [
                'title' => $c->title, 'topics' => $c->topics ?? [], 'duration_hours' => $c->duration_hours, 'method' => $c->method,
            ])->all(),
            'upcoming_schedules' => ScheduleResource::collection($this->getRelationValue('upcomingSchedules') ?? collect())->resolve($request),
        ];
    }
}
