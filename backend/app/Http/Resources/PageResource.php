<?php

namespace App\Http\Resources;

use App\Models\Page;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bentuk identik dengan tipe `StaticPage` frontend (body = HTML). @mixin Page */
class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['slug' => $this->slug, 'title' => $this->title, 'description' => $this->seo_description, 'updatedLabel' => $this->updated_at ? 'Diperbarui '.IdDate::long($this->updated_at) : null, 'body' => $this->body];
    }
}
