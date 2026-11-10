<?php

namespace App\Http\Resources;

use App\Models\Partner;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Partner */
class PartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['name' => $this->name, 'slug' => $this->slug, 'logo' => Media::url($this->logo), 'w' => $this->width, 'h' => $this->height, 'type' => $this->type, 'website' => $this->website, 'official' => $this->is_official];
    }
}
