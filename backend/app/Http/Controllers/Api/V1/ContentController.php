<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PageResource;
use App\Http\Resources\PartnerResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Gallery;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Partner;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Models\TrainingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Endpoint konten CMS sederhana: partner, testimoni, galeri, halaman, kategori, menu, pengaturan. */
class ContentController extends Controller
{
    /** GET /api/v1/partners?type=client|principal|certification&official=1 */
    public function partners(Request $request): AnonymousResourceCollection
    {
        $q = Partner::query()->active();
        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($request->boolean('official')) {
            $q->where('is_official', true);
        }

        return PartnerResource::collection($q->get());
    }

    /** GET /api/v1/testimonials */
    public function testimonials(): AnonymousResourceCollection
    {
        return TestimonialResource::collection(Testimonial::query()->approved()->get());
    }

    /** GET /api/v1/galleries?limit= */
    public function galleries(Request $request): AnonymousResourceCollection
    {
        $q = Gallery::query()->active();
        if ($limit = (int) $request->integer('limit')) {
            $q->limit($limit);
        }

        return GalleryResource::collection($q->get());
    }

    /** GET /api/v1/pages/{slug} */
    public function page(string $slug): PageResource
    {
        return new PageResource(Page::query()->published()->where('slug', $slug)->firstOrFail());
    }

    /** GET /api/v1/categories?home=1 */
    public function categories(Request $request): AnonymousResourceCollection
    {
        $q = $request->boolean('home') ? TrainingCategory::query()->onHome() : TrainingCategory::query()->active()->whereNull('parent_id')->with('children');

        return CategoryResource::collection($q->get());
    }

    /** GET /api/v1/menus → { header: [...], footer: [{title, links}] } */
    public function menus(): JsonResponse
    {
        $all = Menu::query()->active()->get();
        $footer = $all->filter(fn ($m) => str_starts_with($m->location, 'footer_'))->groupBy('location')->sortKeys()
            ->map(fn ($items) => ['title' => $items->first()->group_title, 'links' => MenuResource::collection($items->values())])->values();

        return response()->json(['header' => MenuResource::collection($all->where('location', 'header')->values()), 'footer' => $footer]);
    }

    /** GET /api/v1/settings[?key=site] */
    public function settings(Request $request): JsonResponse
    {
        if ($key = $request->string('key')->toString()) {
            return response()->json(['key' => $key, 'value' => SiteSetting::get($key)]);
        }

        return response()->json(SiteSetting::allValues());
    }
}
