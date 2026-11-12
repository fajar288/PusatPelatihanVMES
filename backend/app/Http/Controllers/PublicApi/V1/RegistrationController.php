<?php

namespace App\Http\Controllers\PublicApi\V1;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiClient;
use App\Http\Requests\PublicApi\StoreApiRegistrationRequest;
use App\Http\Resources\PublicApi\RegistrationResource;
use App\Models\ApiClient;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Services\RegistrationService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** API Publik v1 — pendaftaran atas nama peserta (scope registrations.write / registrations.read). */
class RegistrationController extends Controller
{
    private const WITH = ['schedule.package', 'participants', 'invoices'];

    /** POST /registrations — idempoten bila `external_ref` dikirim ulang (200 + header Idempotent-Replayed). */
    public function store(StoreApiRegistrationRequest $request, RegistrationService $service, WebhookService $webhooks): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validated();

        if (filled($data['external_ref'] ?? null)) {
            $existing = $client->registrations()->where('external_ref', $data['external_ref'])->first();
            if ($existing) {
                return (new RegistrationResource($existing->load(self::WITH)))->response()->setStatusCode(200)->header('Idempotent-Replayed', 'true');
            }
        }

        $schedule = TrainingSchedule::query()->published()->where('code', $data['schedule'])->first();
        if (! $schedule) {
            return AuthenticateApiClient::error('schedule_not_found', 'Jadwal kelas tidak ditemukan atau belum dipublikasikan.', 422);
        }
        if (! $schedule->acceptsRegistrations()) {
            return AuthenticateApiClient::error('registration_closed', 'Pendaftaran untuk jadwal ini tidak dibuka.', 422);
        }
        $count = ($data['type'] ?? null) === Registration::TYPE_CORPORATE ? max(1, (int) ($data['participants_count'] ?? count($data['participants'] ?? []) ?: 1)) : 1;
        if ($schedule->quota !== null && $schedule->remaining() < $count) {
            return AuthenticateApiClient::error('insufficient_seats', "Sisa kursi ({$schedule->remaining()}) tidak cukup untuk $count peserta.", 422);
        }

        $registration = $service->create($data, $schedule, null, $data['participants'] ?? [], 'api', [
            'api_client_id' => $client->id,
            'external_ref' => $data['external_ref'] ?? null,
        ]);
        $webhooks->emit($client, 'registration.created', WebhookService::registrationData($registration));

        return (new RegistrationResource($registration->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** GET /registrations?status=&external_ref=&updated_since=&per_page= — hanya milik klien ini. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(RegistrationStatus::cases(), 'value'))],
            'external_ref' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = $this->client($request)->registrations()->with(self::WITH);
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($ref = $request->string('external_ref')->toString()) {
            $q->where('external_ref', $ref);
        }
        if ($since = $request->input('updated_since')) {
            $q->where('updated_at', '>=', Carbon::parse($since));
        }

        return RegistrationResource::collection($q->latest('id')->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** GET /registrations/{number} */
    public function show(Request $request, string $number): JsonResponse
    {
        $r = $this->client($request)->registrations()->with(self::WITH)->where('registration_number', $number)->first();
        if (! $r) {
            return AuthenticateApiClient::error('not_found', 'Pendaftaran tidak ditemukan.', 404);
        }

        return response()->json(['data' => (new RegistrationResource($r))->resolve()]);
    }

    private function client(Request $request): ApiClient
    {
        return $request->attributes->get(AuthenticateApiClient::ATTRIBUTE);
    }
}
