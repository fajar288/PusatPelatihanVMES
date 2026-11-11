<?php

namespace App\Http\Resources\PublicApi;

use App\Models\TrainingCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API Publik v1 — kategori program. @mixin TrainingCategory */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'parent' => $this->parent_id ? $this->parent?->slug : null,
            'programs_count' => (int) ($this->programs_count ?? 0),
        ];
    }
}
