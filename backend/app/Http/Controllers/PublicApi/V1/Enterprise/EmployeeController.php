<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\OrganizationUnit;
use App\Services\CorporateReporting;
use App\Services\EmploymentService;
use App\Services\IdentityMatchingService;
use App\Services\MemberService;
use App\Support\Enterprise\EnterpriseApiPresenter;
use App\Support\Enterprise\IdentityMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API enterprise — direktori karyawan (Employment Relationship, §4.5, §20.6) & sinkron HRIS.
 * Mencatat karyawan hanya menautkan Member yang SUDAH ada dan teridentifikasi yakin (email terverifikasi / nomor member);
 * API tidak pernah membuat akun (§20.5). Mengakhiri hubungan kerja tidak menghapus member, enrollment, maupun sertifikat (R4, R9).
 */
class EmployeeController extends EnterpriseController
{
    public function __construct(private CorporateReporting $reporting) {}

    /** GET /corporate/employees?status=active|inactive|all&employee_id=&unit=&q=&updated_since= */
    public function index(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, [
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])], 'employee_id' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:60'], 'q' => ['nullable', 'string', 'max:100'],
        ], 200);
        $status = $p['status'] ?? 'active';
        $q = $this->reporting->employeesQuery($cu, activeOnly: false)->with(['member.user:id,name', 'unit'])
            ->when($status !== 'all', fn (Builder $w) => $w->where('status', $status))
            ->when($p['employee_id'] ?? null, fn (Builder $w, $id) => $w->where('employee_id', $id))
            ->when($p['unit'] ?? null, fn (Builder $w, $c) => $w->whereHas('unit', fn (Builder $u) => $u->where('code', $c)))
            ->when($p['q'] ?? null, fn (Builder $w, $s) => $w->where(fn (Builder $x) => $x->where('employee_id', 'like', "%$s%")
                ->orWhereHas('member.user', fn (Builder $u) => $u->where('name', 'like', "%$s%"))))
            ->orderBy('id');
        $response = $this->paginate($request, $q, fn (EmploymentRelationship $e) => EnterpriseApiPresenter::employee($e), 50);
        $this->auditAccess($request, 'api.employees_viewed', null, ['count' => count($response->getData(true)['data'])]);

        return $response;
    }

    /** GET /corporate/employees/{code} */
    public function show(Request $request, string $code): JsonResponse
    {
        $e = $this->find($this->cu($request), $code);

        return $e ? response()->json(['data' => EnterpriseApiPresenter::employee($e)]) : $this->notFound('Karyawan tidak ditemukan.');
    }

    /**
     * POST /corporate/employees — catat hubungan kerja untuk member yang sudah ada (sumber `api`).
     * Sudah aktif → 200 dengan data yang ada (aman diulang). Identitas tidak yakin → 422 `identity_unresolved`.
     */
    public function store(Request $request, IdentityMatchingService $identity, MemberService $members, EmploymentService $employment): JsonResponse
    {
        $cu = $this->cu($request);
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:60'], 'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required_without:member_number', 'nullable', 'email', 'max:255'], 'member_number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'], 'position' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:60'], 'start_date' => ['nullable', 'date'],
        ]);
        $unitId = $this->unitId($cu, $data['unit'] ?? null);
        $org = $cu->organization;

        $match = $identity->match(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'member_number', 'employee_id'])), $org);
        if ($match->outcome !== IdentityMatch::USE_EXISTING) {
            return response()->json(['error' => [
                'code' => 'identity_unresolved',
                'outcome' => $match->outcome,
                'message' => match ($match->outcome) {
                    IdentityMatch::CREATE_NEW => 'Belum ada member VMES dengan email terverifikasi ini. Undang karyawan lewat penugasan peserta (POST /corporate/requests/{code}/participants) agar ia membuat akun sendiri.',
                    IdentityMatch::CONFIRM, IdentityMatch::EXISTING_EMPLOYMENT => 'Ditemukan kandidat member, tetapi belum bisa dipastikan (email belum terverifikasi / hanya HP atau employee ID yang cocok). Minta karyawan memverifikasi email akunnya, atau kirim nomor member.',
                    IdentityMatch::DUPLICATE => 'Member ini tidak bisa dicatat (duplikat).',
                    default => 'Data identitas meragukan (konflik atau nomor member tidak dikenal). Hubungi VMES untuk pemeriksaan.',
                },
            ]], 422);
        }
        $member = $match->member ?? $members->createForUser($match->user);

        $existing = EmploymentRelationship::query()->active()->where('organization_id', $org->id)->where('member_id', $member->id)->with(['member.user', 'unit'])->first();
        if ($existing) {
            return response()->json(['data' => EnterpriseApiPresenter::employee($existing), 'meta' => ['already_active' => true]]);
        }
        $e = $employment->start($member, $org, [
            'employee_id' => $data['employee_id'], 'position' => $data['position'] ?? null, 'unit_id' => $unitId, 'start_date' => $data['start_date'] ?? null,
        ], 'api', $cu->user);

        return response()->json(['data' => EnterpriseApiPresenter::employee($e->load(['member.user', 'unit']))], 201);
    }

    /** POST /corporate/employees/{code}/end — resign / kontrak berakhir / pindah: employment INACTIVE, akses corporate orang itu dicabut. */
    public function end(Request $request, string $code, EmploymentService $employment): JsonResponse
    {
        $cu = $this->cu($request);
        $e = $this->find($cu, $code);
        if (! $e) {
            return $this->notFound('Karyawan tidak ditemukan.');
        }
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(EmploymentRelationship::END_REASONS))],
            'end_date' => ['nullable', 'date'],
        ]);
        $employment->end($e, $data['end_date'] ?? null, $data['reason'], $cu->user);

        return response()->json(['data' => EnterpriseApiPresenter::employee($e->refresh()->load(['member.user', 'unit']))]);
    }

    // ---------------------------------------------------------------------

    private function find(CorporateUser $cu, string $code): ?EmploymentRelationship
    {
        return $this->reporting->employeesQuery($cu, activeOnly: false)->where('code', $code)->with(['member.user:id,name', 'unit'])->first();
    }

    /** Kode unit → id; pengguna dengan batas unit hanya boleh unit dalam batasnya (default: unitnya sendiri). */
    private function unitId(CorporateUser $cu, ?string $code): ?int
    {
        $scope = $cu->unitScope();
        if (! $code) {
            return $cu->unit_id;
        }
        $id = OrganizationUnit::query()->where('organization_id', $cu->organization_id)->where('code', $code)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['unit' => 'Unit tidak ditemukan di organisasi ini.']);
        }
        if ($scope !== null && ! in_array($id, $scope, true)) {
            throw ValidationException::withMessages(['unit' => 'Unit di luar batas akses pengguna perusahaan yang diwakili.']);
        }

        return $id;
    }
}
