<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\TrainingPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    /** GET /api/v1/packages?q=&kategori=&mode=&urut=new|pop|lo|hi|az */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = TrainingPackage::query()->published()->with('category')->search($request->string('q'));

        if ($cat = $request->string('kategori')->toString()) {
            $q->whereHas('category', fn ($c) => $c->where('name', $cat)->orWhere('slug', $cat));
        }
        if ($mode = $request->string('mode')->toString()) {
            $q->where('mode', array_search($mode, TrainingPackage::MODE_LABELS, true) ?: $mode);
        }

        match ($request->string('urut')->toString()) {
            'az' => $q->orderBy('title'),
            'lo' => $q->orderBy('price'),
            'hi' => $q->orderByDesc('price'),
            'pop' => $q->orderByDesc('is_popular')->orderByDesc('published_at'),
            default => $q->orderByDesc('published_at'),
        };

        return PackageResource::collection($q->get())->additional([
            'meta' => [
                'categories' => TrainingPackage::query()->published()->with('category')->get()->pluck('category.name')->filter()->unique()->sort()->values(),
                'modes' => TrainingPackage::query()->published()->pluck('mode')->unique()->map(fn ($m) => TrainingPackage::MODE_LABELS[$m] ?? $m)->sort()->values(),
            ],
        ]);
    }

    /** GET /api/v1/packages/{slug} — detail + jadwal mendatang + kurikulum + terkait */
    public function show(string $slug): PackageResource
    {
        $pkg = TrainingPackage::query()->published()->with(['category', 'curricula'])->where('slug', $slug)->firstOrFail();
        $pkg->setRelation('schedules', $pkg->schedules()->upcoming()->with('package.category')->get());

        $others = TrainingPackage::query()->published()->with('category')->whereKeyNot($pkg->id)->get();
        $related = $others->where('category_id', $pkg->category_id)->concat($others->where('category_id', '!=', $pkg->category_id))->take(3)->values();
        $pkg->setRelation('related', $related);

        return new PackageResource($pkg);
    }
}
