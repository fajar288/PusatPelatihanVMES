<?php

namespace App\Http\Controllers\PublicApi\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Resources\PublicApi\CategoryResource;
use App\Http\Resources\PublicApi\ProgramResource;
use App\Http\Resources\PublicApi\ScheduleResource;
use App\Models\ApiClient;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** API Publik v1 — identitas klien & katalog (scope catalog.read). */
class CatalogController extends Controller
{
    /** GET / — cek koneksi: identitas klien, scope & batas permintaan. */
    public function client(Request $request): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);

        return response()->json(['data' => [
            'api_version' => 'v1',
            'client' => $client->name,
            'organization' => $client->organization,
            'scopes' => array_values($client->scopes ?? []),
            'rate_limit_per_minute' => $client->rate_limit,
            'webhook_events' => $client->webhook_url ? array_values($client->webhook_events ?? []) : [],
            'expires_at' => $client->expires_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /** GET /categories */
    public function categories(): AnonymousResourceCollection
    {
        $items = TrainingCategory::query()->active()->with('parent')
            ->withCount(['packages as programs_count' => fn (Builder $q) => $q->published()])
            ->orderBy('sort_order')->orderBy('name')->get();

        return CategoryResource::collection($items);
    }

    /** GET /programs?q=&category=&mode=&program_type=&updated_since=&per_page= */
    public function programs(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', 'in:'.implode(',', array_keys(TrainingPackage::MODE_LABELS))],
            'program_type' => ['nullable', 'in:'.implode(',', array_keys(TrainingPackage::PROGRAM_TYPES))],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = TrainingPackage::query()->published()->with('category')->search($request->string('q')->toString());
        if ($cat = $request->string('category')->toString()) {
            $q->whereIn('category_id', $this->categoryIds($cat));
        }
        foreach (['mode', 'program_type'] as $field) {
            if ($v = $request->string($field)->toString()) {
                $q->where($field, $v);
            }
        }
        if ($since = $request->input('updated_since')) {
            $q->where('updated_at', '>=', Carbon::parse($since));
        }

        return ProgramResource::collection($q->orderBy('sort_order')->orderBy('title')->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** GET /programs/{slug} — detail + silabus + jadwal mendatang. */
    public function program(string $slug): JsonResponse
    {
        $pkg = TrainingPackage::query()->published()->with(['category', 'curricula'])->where('slug', $slug)->first();
        if (! $pkg) {
            return AuthenticateApiClient::error('not_found', 'Program tidak ditemukan.', 404);
        }
        $pkg->setRelation('upcomingSchedules', $pkg->schedules()->upcoming()->with('package')->get());

        return response()->json(['data' => (new ProgramResource($pkg))->detailed()->resolve()]);
    }

    /** GET /schedules?program=&category=&from=&to=&city=&mode=&open=&include_past=&updated_since=&per_page= */
    public function schedules(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'program' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'city' => ['nullable', 'string', 'max:80'],
            'mode' => ['nullable', 'in:'.implode(',', array_keys(TrainingPackage::MODE_LABELS))],
            'open' => ['nullable', 'boolean'],
            'include_past' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = TrainingSchedule::query()->published()->with('package')
            ->whereHas('package', fn (Builder $p) => $p->published());
        if (! $request->boolean('include_past')) {
            $q->whereDate('end_date', '>=', today());
        }
        if ($program = $request->string('program')->toString()) {
            $q->whereHas('package', fn (Builder $p) => $p->where('slug', $program)->orWhere('code', $program));
        }
        if ($cat = $request->string('category')->toString()) {
            $q->whereHas('package', fn (Builder $p) => $p->whereIn('category_id', $this->categoryIds($cat)));
        }
        if ($from = $request->input('from')) {
            $q->whereDate('start_date', '>=', Carbon::parse($from)->toDateString());
        }
        if ($to = $request->input('to')) {
            $q->whereDate('start_date', '<=', Carbon::parse($to)->toDateString());
        }
        if ($city = $request->string('city')->toString()) {
            $q->whereRaw('LOWER(city) = ?', [mb_strtolower($city)]);
        }
        if ($mode = $request->string('mode')->toString()) {
            $q->where('mode', $mode);
        }
        if ($request->boolean('open')) {
            $q->whereIn('status', ['registration_open', 'confirmed'])
                ->where(fn (Builder $w) => $w->whereNull('registration_deadline')->orWhereDate('registration_deadline', '>=', today()));
        }
        if ($since = $request->input('updated_since')) {
            $q->where('updated_at', '>=', Carbon::parse($since));
        }

        return ScheduleResource::collection($q->orderBy('start_date')->orderBy('code')->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** GET /schedules/{code} */
    public function schedule(string $code): JsonResponse
    {
        $s = TrainingSchedule::query()->published()->with('package')->where('code', $code)->first();
        if (! $s) {
            return AuthenticateApiClient::error('not_found', 'Jadwal kelas tidak ditemukan.', 404);
        }

        return response()->json(['data' => (new ScheduleResource($s))->resolve()]);
    }

    /** Kategori + seluruh turunannya (slug). @return int[] */
    private function categoryIds(string $slug): array
    {
        $root = TrainingCategory::query()->where('slug', $slug)->first();
        if (! $root) {
            return [0];
        }
        $ids = [$root->id];
        $frontier = [$root->id];
        while ($frontier) {
            $frontier = TrainingCategory::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
