<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Services\CorporateAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API pengguna perusahaan (Roadmap 5 tahap 5A — dasar Corporate Portal 5B).
 * Organisasi diambil dari URL tetapi SELALU diverifikasi terhadap keanggotaan aktif user (§20.21):
 * organisasi lain → 404 (tidak membocorkan keberadaannya). Data hanya enrollment bersponsor (R3).
 */
class CorporateController extends Controller
{
    public function __construct(private CorporateAccess $access) {}

    /** GET /corporate/organizations — organisasi tempat user menjadi pengguna perusahaan aktif. */
    public function organizations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access->memberships($request->user())->map(fn (CorporateUser $cu) => [
            'code' => $cu->organization->code,
            'name' => $cu->organization->name(),
            'role' => $cu->role,
            'roleLabel' => CorporateUser::ROLES[$cu->role] ?? $cu->role,
            'unit' => $cu->unit?->label(),
        ])->values()]);
    }

    /** GET /corporate/organizations/{organization}/enrollments — peserta bersponsor dalam scope pengguna. */
    public function enrollments(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('viewCorporate', $organization);
        $cu = $this->access->membership($request->user(), $organization);
        $cu->setRelation('organization', $organization);

        $rows = $this->access->listableEnrollments($cu)
            ->with(['member.user:id,name', 'schedule.package:id,title', 'certificate:id,enrollment_id,status,number', 'sponsorUnit:id,name,code'])
            ->latest('id')->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        $active = EmploymentRelationship::query()->active()->where('organization_id', $organization->id)
            ->whereIn('member_id', $rows->getCollection()->pluck('member_id')->filter())->get()->keyBy('member_id');

        AuditLog::record('corporate.participants_viewed', $organization, null, ['corporate_user_id' => $cu->id, 'page' => $rows->currentPage(), 'count' => $rows->count()], $request->user());

        return response()->json([
            'data' => $rows->getCollection()->map(function (Enrollment $e) use ($cu, $active) {
                $progress = $this->access->canViewProgress($cu, $e);
                $employment = $e->member_id ? $active->get($e->member_id) : null;

                return [
                    'id' => $e->id,
                    'name' => $e->member?->user?->name ?? $e->participant_name,
                    'memberNumber' => $e->member?->member_number,
                    'employeeId' => $employment?->employee_id,
                    'employment' => $employment ? 'active' : ($this->access->hasExited($e) ? 'inactive' : 'unknown'),
                    'program' => $e->schedule?->package?->title ?? $e->schedule?->title,
                    'schedule' => $e->schedule?->code,
                    'unit' => $e->sponsorUnit?->label(),
                    'status' => $e->status,
                    'attendancePercent' => $progress ? $e->attendancePercent() : null,
                    'progressVisible' => $progress,
                    'certificateStatus' => $this->access->canViewCertificateStatus($cu, $e) ? $e->certificate?->status : null,
                ];
            })->values(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }
}
