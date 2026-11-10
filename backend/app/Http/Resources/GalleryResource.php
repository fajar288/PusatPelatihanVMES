<?php

namespace App\Http\Resources;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Gallery */
class GalleryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['src' => $this->image, 'alt' => $this->title, 'caption' => $this->caption, 'category' => $this->category, 'date' => $this->event_date?->toDateString()];
    }
}
