<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleResource;
use App\Models\TrainingSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    /** GET /api/v1/schedules?kategori=&paket=&q=&semua=1 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = TrainingSchedule::query()->with('package.category');
        $request->boolean('semua') ? $q->published()->orderBy('start_date') : $q->upcoming();

        if ($cat = $request->string('kategori')->toString()) {
            $q->whereHas('package.category', fn ($c) => $c->where('name', $cat)->orWhere('slug', $cat));
        }
        if ($pkg = $request->string('paket')->toString()) {
            $q->whereHas('package', fn ($p) => $p->where('slug', $pkg));
        }
        if ($term = trim($request->string('q')->toString())) {
            $like = '%'.mb_strtolower($term).'%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', [$like])
                ->orWhereHas('package', fn ($p) => $p->whereRaw('LOWER(title) LIKE ?', [$like])));
        }

        return ScheduleResource::collection($q->get());
    }

    /** GET /api/v1/schedules/{code} */
    public function show(TrainingSchedule $schedule): ScheduleResource
    {
        abort_unless(in_array($schedule->status, TrainingSchedule::PUBLIC_STATUSES, true), 404);
        $schedule->load('package.category');

        $others = TrainingSchedule::query()->upcoming()->with('package.category')->whereKeyNot($schedule->id)->get();
        $related = $others->where('package_id', $schedule->package_id)->concat($others->where('package_id', '!=', $schedule->package_id))->take(3)->values();

        return (new ScheduleResource($schedule))->additional([
            'package' => $schedule->package ? [
                'slug' => $schedule->package->slug,
                'title' => $schedule->package->title,
                'desc' => $schedule->package->excerpt,
                'overview' => [
                    'intro' => $schedule->package->overview_intro ?? [],
                    'learn' => $schedule->package->learning_outcomes ?? [],
                    'requirements' => $schedule->package->requirements ?? [],
                ],
            ] : null,
            'related' => ScheduleResource::collection($related),
        ]);
    }
}
