<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Organization;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;
use App\Services\ParticipantAssignmentService;
use App\Services\TrainingRequestService;
use App\Support\IdDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Corporate Training Request & kursi (Roadmap 5 §4.2, §4.3, §20.3, §20.4, §20.13) di bawah
 * /corporate/organizations/{org}/requests. Organisasi & batas unit diverifikasi lewat CorporateController::member().
 */
class CorporateRequestController extends Controller
{
    public function __construct(
        private CorporateController $corporate,
        private TrainingRequestService $requests,
        private ParticipantAssignmentService $assignments,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $rows = $this->corporate->requestsQuery($cu)->with(['package:id,title', 'schedule:id,code,start_date,end_date', 'unit'])->withCount([
            'requestSeats as filled_count' => fn ($q) => $q->whereIn('status', ['assigned', 'enrolled']),
            'requestSeats as invited_count' => fn ($q) => $q->where('status', 'invited'),
        ])->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (TrainingRequest $r) => $this->present($cu, $r, false))->values()]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $data = $this->validateRequest($request);
        $r = $this->requests->create($cu, $data, (bool) ($data['submit'] ?? false));

        return response()->json(['data' => $this->present($cu, $r->fresh(['package', 'unit', 'requestSeats']), true)], 201);
    }

    public function show(Request $request, Organization $organization, TrainingRequest $trainingRequest): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $r = $this->scoped($cu, $trainingRequest);

        return response()->json(['data' => $this->present($cu, $r->load(['package', 'schedule', 'unit', 'requestSeats.unit', 'requestSeats.pendingInvitation']), true)]);
    }

    public function update(Request $request, Organization $organization, TrainingRequest $trainingRequest): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $r = $this->requests->update($this->scoped($cu, $trainingRequest), $cu, $this->validateRequest($request));

        return response()->json(['data' => $this->present($cu, $r->fresh(['package', 'unit', 'requestSeats']), true)]);
    }

    /** POST .../requests/{code}/{action} — submit | accept-quote | approve | cancel */
    public function transition(Request $request, Organization $organization, TrainingRequest $trainingRequest, string $action): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $r = $this->scoped($cu, $trainingRequest);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        match ($action) {
            'submit' => $this->requests->submit($r, $cu),
            'accept-quote' => $this->requests->acceptQuote($r, $cu),
            'approve' => $this->requests->approve($r, $cu, $note),
            'cancel' => $this->requests->cancel($r, $request->user(), $note ?: 'Dibatalkan perusahaan', $cu),
            default => abort(404),
        };

        return $this->show($request, $organization, $r->refresh());
    }

    /** POST .../requests/{code}/seats/{seat}/assign — manual (identity matching → tetapkan / undang / review). */
    public function assign(Request $request, Organization $organization, TrainingRequest $trainingRequest, RequestSeat $seat): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $seat = $this->seat($cu, $trainingRequest, $seat);
        $person = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'string', 'max:60'], 'member_number' => ['nullable', 'string', 'max:30'], 'unit_id' => ['nullable', 'integer'], 'position' => ['nullable', 'string', 'max:255'],
        ]);
        $res = $this->assignments->assign($seat, $cu, $person);

        return response()->json(['data' => ['status' => $res['status'], 'message' => $res['message'], 'seat' => $res['seat']->seat_no]]);
    }

    /** POST .../seats/{seat}/assign-employee — pilih dari karyawan aktif. */
    public function assignEmployee(Request $request, Organization $organization, TrainingRequest $trainingRequest, RequestSeat $seat): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $seat = $this->seat($cu, $trainingRequest, $seat);
        $id = $request->validate(['employment_id' => ['required', 'integer']])['employment_id'];
        $employment = EmploymentRelationship::query()->where('organization_id', $organization->id)->findOrFail($id);
        $res = $this->assignments->assignEmployee($seat, $cu, $employment);

        return response()->json(['data' => ['status' => $res['status'], 'message' => $res['message'], 'seat' => $res['seat']->seat_no]]);
    }

    /** POST .../seats/{seat}/release — batalkan peserta di kursi ini (hanya enrollment-nya yang berubah). */
    public function release(Request $request, Organization $organization, TrainingRequest $trainingRequest, RequestSeat $seat): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $seat = $this->seat($cu, $trainingRequest, $seat);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->assignments->releaseSeat($seat, $request->user(), $reason, $cu);

        return response()->json(['ok' => true]);
    }

    public function template(Request $request, Organization $organization, TrainingRequest $trainingRequest): Response
    {
        $this->scoped($this->corporate->member($request, $organization), $trainingRequest);

        return response("\xEF\xBB\xBF".ParticipantAssignmentService::templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-peserta.csv"',
        ]);
    }

    /** POST .../requests/{code}/import — CSV/XLSX, laporan per baris. */
    public function import(Request $request, Organization $organization, TrainingRequest $trainingRequest): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $r = $this->scoped($cu, $trainingRequest);
        $file = $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx']])['file'];
        $report = $this->assignments->import($r, $cu, $file);

        return response()->json(['data' => $report, 'summary' => collect($report)->countBy('status')->all()]);
    }

    // ---------------------------------------------------------------------

    private function scoped(CorporateUser $cu, TrainingRequest $r): TrainingRequest
    {
        abort_unless($r->organization_id === $cu->organization_id, 404);
        $scope = $cu->unitScope();
        abort_if($scope !== null && ! in_array($r->unit_id, $scope, true), 404);

        return $r;
    }

    private function seat(CorporateUser $cu, TrainingRequest $r, RequestSeat $seat): RequestSeat
    {
        $this->scoped($cu, $r);
        abort_unless($seat->training_request_id === $r->id, 404);
        $seat->setRelation('request', $r);

        return $seat;
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'package_id' => ['required', 'integer'], 'seats' => ['required', 'integer', 'min:1', 'max:500'],
            'delivery' => ['required', Rule::in(array_keys(TrainingRequest::DELIVERY))], 'target_date' => ['nullable', 'date', 'after_or_equal:today'],
            'location' => ['nullable', 'string', 'max:255'], 'unit_id' => ['nullable', 'integer'],
            'trainer_requirements' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'], 'submit' => ['nullable', 'boolean'],
        ]);
    }

    private function present(CorporateUser $cu, TrainingRequest $r, bool $detail): array
    {
        $finance = $cu->allows('view_finance') || $cu->allows('manage_requests') || $cu->allows('approve_requests');
        $base = [
            'code' => $r->code,
            'status' => $r->status,
            'statusLabel' => $r->label(),
            'program' => $r->package?->title,
            'packageId' => $r->package_id,
            'delivery' => $r->delivery,
            'deliveryLabel' => TrainingRequest::DELIVERY[$r->delivery] ?? $r->delivery,
            'targetDate' => $r->target_date?->toDateString(),
            'targetLabel' => IdDate::long($r->target_date),
            'location' => $r->location,
            'unit' => $r->unit?->label(),
            'unitId' => $r->unit_id,
            'seats' => $r->seats,
            'filled' => $r->filled_count ?? $r->requestSeats()->whereIn('status', ['assigned', 'enrolled'])->count(),
            'invited' => $r->invited_count ?? $r->requestSeats()->where('status', 'invited')->count(),
            'schedule' => $r->schedule ? ['code' => $r->schedule->code, 'dates' => IdDate::long($r->schedule->start_date).' – '.IdDate::long($r->schedule->end_date)] : null,
            'createdAt' => IdDate::long($r->created_at),
            'quote' => $finance && $r->quote_total !== null ? [
                'pricePerSeat' => (float) $r->price_per_seat, 'discount' => (float) $r->quote_discount, 'total' => (float) $r->quote_total,
                'validUntil' => IdDate::long($r->quote_valid_until), 'notes' => $r->quote_notes, 'expired' => $r->quote_valid_until?->isBefore(today()) ?? false,
            ] : null,
            'can' => [
                'edit' => $r->status === 'draft' && $cu->allows('manage_requests'),
                'submit' => $r->status === 'draft' && $cu->allows('manage_requests'),
                'acceptQuote' => $r->status === 'quoted' && $cu->allows('manage_requests'),
                'approve' => $r->status === 'approval' && $cu->allows('approve_requests'),
                'cancel' => in_array($r->status, ['draft', 'submitted', 'quoted', 'approval'], true) && $cu->allows('manage_requests'),
                'assign' => $r->isAssignable() && $cu->allows('manage_participants'),
            ],
        ];
        if (! $detail) {
            return $base;
        }

        return $base + [
            'trainerRequirements' => $r->trainer_requirements,
            'notes' => $r->notes,
            'cancelReason' => $r->cancel_reason,
            'timeline' => array_filter([
                'Dibuat' => IdDate::long($r->created_at), 'Dikirim' => IdDate::long($r->submitted_at), 'Penawaran' => IdDate::long($r->quoted_at),
                'Penawaran diterima' => IdDate::long($r->quote_accepted_at), 'Disetujui' => IdDate::long($r->approved_at), 'Lunas' => IdDate::long($r->paid_at),
                'Terjadwal' => IdDate::long($r->scheduled_at), 'Dibatalkan' => IdDate::long($r->cancelled_at),
            ]),
            'seatList' => $r->requestSeats->map(fn (RequestSeat $s) => [
                'id' => $s->id, 'no' => $s->seat_no, 'status' => $s->status, 'statusLabel' => RequestSeat::STATUSES[$s->status] ?? $s->status,
                'name' => $s->participant_name, 'email' => $s->participant_email, 'unit' => $s->unit?->label(), 'method' => $s->assignment_method,
                'enrollmentId' => $s->enrollment_id, 'inviteExpires' => IdDate::long($s->pendingInvitation?->expires_at),
            ])->values(),
        ];
    }
}
