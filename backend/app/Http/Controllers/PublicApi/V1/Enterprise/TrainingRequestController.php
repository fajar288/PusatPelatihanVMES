<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\OrganizationUnit;
use App\Models\RequestSeat;
use App\Models\TrainingPackage;
use App\Models\TrainingRequest;
use App\Services\CorporateReporting;
use App\Services\ParticipantAssignmentService;
use App\Services\TrainingRequestService;
use App\Support\Enterprise\EnterpriseApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API enterprise — Corporate Training Request (§4.2, §20.3, §20.13) & penugasan peserta ke kursi (§4.3, §20.4).
 * Memakai service yang sama dengan Corporate Portal: identity matching, aturan status, audit, notifikasi.
 */
class TrainingRequestController extends EnterpriseController
{
    private const LIST_WITH = ['package:id,slug,title', 'schedule:id,code,title,start_date,end_date,package_id', 'unit'];

    public function __construct(
        private CorporateReporting $reporting,
        private TrainingRequestService $requests,
        private ParticipantAssignmentService $assignments,
    ) {}

    /** GET /corporate/requests?status=&updated_since= */
    public function index(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, ['status' => ['nullable', Rule::in(array_keys(TrainingRequest::STATUSES))]]);
        $q = $this->reporting->requestsQuery($cu)->with(self::LIST_WITH)->withCount([
            'requestSeats as filled_count' => fn ($q) => $q->whereIn('status', ['assigned', 'enrolled']),
            'requestSeats as invited_count' => fn ($q) => $q->where('status', 'invited'),
        ])->when($p['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id');

        return $this->paginate($request, $q, fn (TrainingRequest $r) => EnterpriseApiPresenter::trainingRequest($r, $cu));
    }

    /** GET /corporate/requests/{code} */
    public function show(Request $request, string $code): JsonResponse
    {
        $cu = $this->cu($request);
        $r = $this->find($cu, $code);

        return $r ? $this->detail($cu, $r) : $this->notFound('Request tidak ditemukan.');
    }

    /** POST /corporate/requests — kursi dibuat tanpa membuat Member (§20.22). `submit=true` langsung dikirim ke VMES. */
    public function store(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $data = $request->validate([
            'program' => ['required', 'string', 'max:255'], 'seats' => ['required', 'integer', 'min:1', 'max:500'],
            'delivery' => ['required', Rule::in(array_keys(TrainingRequest::DELIVERY))], 'target_date' => ['nullable', 'date', 'after_or_equal:today'],
            'location' => ['nullable', 'string', 'max:255'], 'unit' => ['nullable', 'string', 'max:60'],
            'trainer_requirements' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'], 'submit' => ['nullable', 'boolean'],
        ]);
        $package = TrainingPackage::query()->published()->where(fn ($q) => $q->where('slug', $data['program'])->orWhere('code', $data['program']))->value('id');
        if (! $package) {
            throw ValidationException::withMessages(['program' => 'Program tidak ditemukan. Pakai slug/kode dari GET /programs.']);
        }
        $data['package_id'] = $package;
        $data['unit_id'] = $this->unitId($cu, $data['unit'] ?? null);
        unset($data['program'], $data['unit']);

        $r = $this->requests->create($cu, $data, (bool) ($data['submit'] ?? false));

        return $this->detail($cu, $r->refresh(), 201);
    }

    /** POST /corporate/requests/{code}/{action} — submit | accept-quote | approve | cancel */
    public function transition(Request $request, string $code, string $action): JsonResponse
    {
        $cu = $this->cu($request);
        $r = $this->find($cu, $code);
        if (! $r) {
            return $this->notFound('Request tidak ditemukan.');
        }
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        match ($action) {
            'submit' => $this->requests->submit($r, $cu),
            'accept-quote' => $this->requests->acceptQuote($r, $cu),
            'approve' => $this->requests->approve($r, $cu, $note),
            'cancel' => $this->requests->cancel($r, $cu->user, $note ?: 'Dibatalkan perusahaan lewat API', $cu),
        };

        return $this->detail($cu, $r->refresh());
    }

    /**
     * POST /corporate/requests/{code}/participants — tetapkan peserta ke kursi kosong (atau `seat` tertentu).
     * `employment` (kode EMP-…) = karyawan aktif yang sudah tertaut; selain itu data orang → identity matching:
     * member terverifikasi dipakai ulang, kandidat diundang lewat email, data meragukan ke review VMES.
     */
    public function assign(Request $request, string $code): JsonResponse
    {
        $cu = $this->cu($request);
        $r = $this->find($cu, $code);
        if (! $r) {
            return $this->notFound('Request tidak ditemukan.');
        }
        $data = $request->validate([
            'seat' => ['nullable', 'integer', 'min:1'],
            'employment' => ['nullable', 'string', 'max:30'],
            'name' => ['required_without:employment', 'nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'string', 'max:60'], 'member_number' => ['nullable', 'string', 'max:30'], 'unit' => ['nullable', 'string', 'max:60'], 'position' => ['nullable', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($cu, $r, $data) {
            $seat = $this->freeSeat($r, $data['seat'] ?? null);
            if (! empty($data['employment'])) {
                $employment = EmploymentRelationship::query()->where('organization_id', $cu->organization_id)->where('code', $data['employment'])->first();
                if (! $employment) {
                    throw ValidationException::withMessages(['employment' => 'Karyawan tidak ditemukan di organisasi ini.']);
                }

                return $this->assignments->assignEmployee($seat, $cu, $employment);
            }
            $person = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'employee_id', 'member_number', 'position']));
            $person['unit_id'] = $this->unitId($cu, $data['unit'] ?? null);

            return $this->assignments->assign($seat, $cu, $person);
        });
        $seat = $result['seat']->refresh()->load('unit');

        return response()->json(['data' => [
            'result' => $result['status'],
            'message' => $result['message'],
            'request' => $r->code,
            'seat' => EnterpriseApiPresenter::seat($seat),
        ]], $result['status'] === 'review' ? 202 : 201);
    }

    /** POST /corporate/requests/{code}/seats/{no}/release — batalkan satu peserta; hanya enrollment kursi itu yang berubah (§11). */
    public function release(Request $request, string $code, int $no): JsonResponse
    {
        $cu = $this->cu($request);
        $r = $this->find($cu, $code);
        $seat = $r?->requestSeats()->where('seat_no', $no)->first();
        if (! $seat) {
            return $this->notFound('Kursi tidak ditemukan.');
        }
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $seat->setRelation('request', $r);
        $enrollmentId = $seat->enrollment_id;
        $this->assignments->releaseSeat($seat, $cu->user, $reason, $cu);

        return response()->json(['data' => [
            'request' => $r->code,
            'seat' => EnterpriseApiPresenter::seat($seat->refresh()->load('unit')),
            'dropped_enrollment_id' => $enrollmentId,
        ]]);
    }

    // ---------------------------------------------------------------------

    private function find(CorporateUser $cu, string $code): ?TrainingRequest
    {
        return $this->reporting->requestsQuery($cu)->where('code', $code)->first();
    }

    private function detail(CorporateUser $cu, TrainingRequest $r, int $status = 200): JsonResponse
    {
        $r->load([...self::LIST_WITH, 'requestSeats.unit']);

        return response()->json(['data' => EnterpriseApiPresenter::trainingRequest($r, $cu, true)], $status);
    }

    /** Kursi kosong pertama (urut nomor) atau kursi yang diminta. */
    private function freeSeat(TrainingRequest $r, ?int $no): RequestSeat
    {
        $q = $r->requestSeats()->lockForUpdate();
        $seat = $no ? $q->where('seat_no', $no)->first() : $q->where('status', 'reserved')->orderBy('seat_no')->first();
        if (! $seat) {
            throw ValidationException::withMessages(['seat' => $no ? "Kursi $no tidak ada di request ini." : 'Tidak ada kursi kosong. Batalkan peserta lain atau minta tambahan kursi ke VMES.']);
        }
        $seat->setRelation('request', $r);

        return $seat;
    }

    /** Kode unit → id (unit harus milik organisasi; batas unit dicek service). */
    private function unitId(CorporateUser $cu, ?string $code): ?int
    {
        if (! $code) {
            return null;
        }
        $id = OrganizationUnit::query()->where('organization_id', $cu->organization_id)->where('code', $code)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['unit' => 'Unit tidak ditemukan di organisasi ini.']);
        }

        return $id;
    }
}
