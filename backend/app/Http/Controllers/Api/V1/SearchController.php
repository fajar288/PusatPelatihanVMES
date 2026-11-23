<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Post;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/search?q= → kursus, jadwal, postingan (sama dengan halaman /search frontend). */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        if ($term === '') {
            return response()->json(['data' => ['q' => '', 'total' => 0, 'courses' => [], 'schedules' => [], 'posts' => []]]);
        }
        $courses = TrainingPackage::query()->published()->with('category')->search($term)->orderByDesc('published_at')->get();
        $schedules = TrainingSchedule::query()->upcoming()->with('package.category')
            ->where(fn ($w) => Sql::orWhereContains(Sql::whereContains($w, 'code', $term), 'city', $term)
                ->orWhereHas('package', fn ($p) => Sql::whereContains($p, 'title', $term)->orWhereHas('category', fn ($c) => Sql::whereContains($c, 'name', $term))))
            ->get();
        $posts = Post::query()->published()->search($term)->get();

        return response()->json(['data' => [
            'q' => $term,
            'total' => $courses->count() + $schedules->count() + $posts->count(),
            'courses' => PackageResource::collection($courses)->resolve($request),
            'schedules' => ScheduleResource::collection($schedules)->resolve($request),
            'posts' => PostResource::collection($posts)->resolve($request),
        ]]);
    }
}
