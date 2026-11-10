<?php

namespace App\Http\Resources;

use App\Models\TrainingCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrainingCategory */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['slug' => $this->slug, 'name' => $this->name, 'title' => $this->name, 'desc' => $this->description, 'icon' => $this->icon, 'href' => '/kursus?kategori='.rawurlencode($this->name), 'children' => CategoryResource::collection($this->whenLoaded('children'))];
    }
}
