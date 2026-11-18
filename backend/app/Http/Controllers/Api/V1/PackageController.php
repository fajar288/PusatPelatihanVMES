<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    /** Urutan tampil level di filter katalog; level lain menyusul berdasar abjad. */
    private const LEVEL_ORDER = ['Fundamental', 'Beginner', 'Dasar', 'Intermediate', 'Menengah', 'Advanced', 'Lanjutan', 'Expert', 'Professional'];

    /** GET /api/v1/packages?q=&kategori=&mode=&level=&bulan=YYYY-MM&urut=new|pop|lo|hi|az */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = TrainingPackage::query()->published()->with('category')->search($request->string('q'));

        if ($cat = $request->string('kategori')->toString()) {
            $q->whereHas('category', fn ($c) => $c->where('name', $cat)->orWhere('slug', $cat));
        }
        if ($mode = $request->string('mode')->toString()) {
            $q->where('mode', array_search($mode, TrainingPackage::MODE_LABELS, true) ?: $mode);
        }
        if ($level = $request->string('level')->toString()) {
            $q->where('level', $level);
        }
        // bulan = jadwal yang mulai pada bulan tersebut (hanya jadwal mendatang yang tampil di publik)
        $month = $request->string('bulan')->toString();
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $q->whereHas('schedules', fn ($s) => $this->upcomingStarts($s)
                ->whereBetween('start_date', [Carbon::parse($month.'-01')->startOfMonth()->toDateString(), Carbon::parse($month.'-01')->endOfMonth()->toDateString()]));
        }

        match ($request->string('urut')->toString()) {
            'az' => $q->orderBy('title'),
            'lo' => $q->orderBy('price'),
            'hi' => $q->orderByDesc('price'),
            'pop' => $q->orderByDesc('is_popular')->orderByDesc('published_at'),
            default => $q->orderByDesc('published_at'),
        };

        $packages = $q->get();
        // bulan mulai jadwal mendatang per paket (satu kueri), dipakai filter "Jadwal" di katalog
        $months = $this->upcomingStarts(TrainingSchedule::query())->whereIn('package_id', $packages->modelKeys())
            ->get(['package_id', 'start_date'])->groupBy('package_id')
            ->map(fn ($rows) => $rows->map(fn ($s) => $s->start_date->format('Y-m'))->unique()->sort()->values()->all());
        $packages->each(fn (TrainingPackage $p) => $p->setAttribute('schedule_months', $months[$p->id] ?? []));

        $published = TrainingPackage::query()->published();
        $allMonths = $this->upcomingStarts(TrainingSchedule::query())->whereIn('package_id', (clone $published)->select('id'))
            ->pluck('start_date')->map(fn ($d) => $d->format('Y-m'))->unique()->sort()->values();

        return PackageResource::collection($packages)->additional([
            'meta' => [
                'categories' => (clone $published)->with('category')->get()->pluck('category.name')->filter()->unique()->sort()->values(),
                'modes' => (clone $published)->pluck('mode')->unique()->map(fn ($m) => TrainingPackage::MODE_LABELS[$m] ?? $m)->sort()->values(),
                'levels' => (clone $published)->whereNotNull('level')->where('level', '!=', '')->pluck('level')->unique()
                    ->sortBy(fn ($l) => [($i = array_search($l, self::LEVEL_ORDER, true)) === false ? 99 : $i, $l])->values(),
                'months' => $allMonths->map(fn (string $m) => ['value' => $m, 'label' => IdDate::monthYear(Carbon::parse($m.'-01'))])->values(),
            ],
        ]);
    }

    /** Jadwal publik yang belum dimulai (atau mulai hari ini). */
    private function upcomingStarts(Builder|HasMany $q): Builder|HasMany
    {
        return $q->published()->whereDate('start_date', '>=', today());
    }

    /** GET /api/v1/packages/{slug}: detail + jadwal mendatang + kurikulum + terkait */
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
