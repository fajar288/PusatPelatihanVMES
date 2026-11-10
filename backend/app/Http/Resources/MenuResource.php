<?php

namespace App\Http\Resources;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Menu */
class MenuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['label' => $this->label, 'href' => $this->url, 'external' => $this->is_external, 'group' => $this->group_title, 'location' => $this->location];
    }
}
