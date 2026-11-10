<?php

namespace App\Http\Resources;

use App\Models\Testimonial;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Bentuk identik dengan tipe `Testimonial` frontend. @mixin Testimonial */
class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['name' => $this->name, 'role' => $this->roleLabel(), 'quote' => $this->content, 'image' => Media::url($this->photo), 'program' => $this->program, 'stars' => $this->rating, 'featured' => $this->is_featured];
    }
}
