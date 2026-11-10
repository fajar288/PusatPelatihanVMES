<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\PackageResource;
use App\Http\Resources\PartnerResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Banner;
use App\Models\Gallery;
use App\Models\Partner;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** GET /api/v1/home — agregat beranda, urutan section identik dengan pusatpelatihan.id. Cache 5 menit. */
class HomeController extends Controller
{
    public const CACHE_KEY = 'api.v1.home';

    public function __invoke(Request $request): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, 300, fn () => [
            'site' => SiteSetting::get('site'),
            'hero' => (SiteSetting::get('hero') ?? []) + [
                'slides' => Banner::query()->live('hero')->get()->map(fn ($b) => ['src' => $b->image, 'alt' => $b->alt, 'link' => $b->link])->values(),
                'officialPartners' => PartnerResource::collection(Partner::query()->official()->get())->resolve($request),
            ],
            'stats' => SiteSetting::get('stats', []),
            'about' => SiteSetting::get('about'),
            'courses' => PackageResource::collection(TrainingPackage::query()->published()->with('category')->orderByDesc('is_popular')->orderByDesc('published_at')->limit(6)->get())->resolve($request),
            'categories' => CategoryResource::collection(TrainingCategory::query()->onHome()->get())->resolve($request),
            'schedules' => ScheduleResource::collection(TrainingSchedule::query()->upcoming()->with('package.category')->limit(8)->get())->resolve($request),
            'partners' => PartnerResource::collection(Partner::query()->active()->where('type', 'client')->get())->resolve($request),
            'why' => SiteSetting::get('why'),
            'testimonials' => TestimonialResource::collection(Testimonial::query()->approved()->get())->resolve($request),
            'gallery' => GalleryResource::collection(Gallery::query()->active()->limit(6)->get())->resolve($request),
            'articles' => PostResource::collection(Post::query()->published()->where('type', 'tutorial')->limit(3)->get())->resolve($request),
            'cta' => SiteSetting::get('cta'),
        ]);

        return response()->json(['data' => $data]);
    }
}
