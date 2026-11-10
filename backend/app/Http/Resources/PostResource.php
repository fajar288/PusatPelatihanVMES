<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\IdDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bentuk identik dengan tipe `Article` di frontend; `body` (HTML) hanya pada detail. @mixin Post */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $full = $request->route()?->getName() === 'api.v1.posts.show' || $request->boolean('full');

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'type' => $this->type,
            'category' => $this->typeLabel(),
            'categorySlug' => $this->typeSlug(),
            'dateLabel' => IdDate::short($this->published_at),
            'publishedAt' => $this->published_at?->timestamp,
            'image' => $this->thumbnail,
            'author' => $this->author_name ?? $this->author?->name,
            'tags' => $this->tags ?? [],
            'body' => $this->when($full, $this->body),
            'related' => PostResource::collection($this->whenLoaded('related')),
            'seo' => $this->when($full, ['title' => $this->seo_title, 'description' => $this->seo_description]),
        ];
    }
}
