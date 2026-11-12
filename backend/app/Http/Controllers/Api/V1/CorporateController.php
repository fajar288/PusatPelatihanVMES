<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\CorporateAccess;
use App\Services\CorporateReporting;
use App\Services\OrganizationService;
use App\Support\IdDate;
use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API Corporate Portal (Roadmap 5 §4.6, §20.9–§20.12, §20.21). Organisasi dari URL SELALU diverifikasi terhadap
 * keanggotaan aktif user (OrganizationPolicy, tolak sebagai 404). Data peserta hanya enrollment bersponsor (R3),
 * dalam batas unit pengguna, mengikuti kebijakan exit_access. Akses peserta, ekspor & perubahan peran diaudit.
 */
class CorporateController extends Controller
{
    public function __construct(private CorporateAccess $access, private CorporateReporting $reporting) {}

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

    /** GET /corporate/organizations/{org} — profil singkat, peran, kemampuan, ringkasan dashboard (§20.9). */
    public function summary(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);

        return response()->json(['data' => [
            'organization' => ['code' => $organization->code, 'name' => $organization->name(), 'legalName' => $organization->legal_name, 'logo' => Media::url($organization->logo), 'requiresApproval' => $organization->requiresApproval()],
            'me' => ['role' => $cu->role, 'roleLabel' => CorporateUser::ROLES[$cu->role], 'unit' => $cu->unit?->label(), 'capabilities' => CorporateUser::CAPABILITIES[$cu->role] ?? []],
            'stats' => $this->reporting->stats($cu),
        ]]);
    }

    /** GET /corporate/organizations/{org}/enrollments — peserta bersponsor dalam scope pengguna. */
    public function enrollments(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('view_progress') || $cu->allows('manage_participants'), 403, 'Peran Anda tidak dapat melihat daftar peserta.');

        $rows = $this->access->listableEnrollments($cu)
            ->with(['member.user:id,name', 'schedule.package:id,title', 'certificate:id,enrollment_id,status,number', 'sponsorUnit:id,name,code'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn (Builder $q) => $q->where('participant_name', 'like', '%'.$request->string('q').'%'))
            ->latest('id')->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        $active = EmploymentRelationship::query()->active()->where('organization_id', $organization->id)
            ->whereIn('member_id', $rows->getCollection()->pluck('member_id')->filter())->get()->keyBy('member_id');

        AuditLog::record('corporate.participants_viewed', $organization, null, ['corporate_user_id' => $cu->id, 'page' => $rows->currentPage(), 'count' => $rows->count()], $request->user());

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Enrollment $e) => $this->enrollmentRow($cu, $e, $active->get($e->member_id)))->values(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /corporate/organizations/{org}/enrollments/{enrollment} — ringkasan progres (§20.10) tanpa catatan privat trainer. */
    public function enrollment(Request $request, Organization $organization, Enrollment $enrollment): JsonResponse
    {
        $cu = $this->member($request, $organization);
        Gate::authorize('viewEnrollmentProgress', [$organization, $enrollment]);
        $o = $this->reporting->progress($enrollment);   // hanya logbook & penilaian tervalidasi, tanpa catatan privat trainer
        $employment = EmploymentRelationship::query()->where('member_id', $enrollment->member_id)->where('organization_id', $organization->id)->latest('id')->first();
        AuditLog::record('corporate.participant_progress_viewed', $enrollment, null, ['organization_id' => $organization->id, 'corporate_user_id' => $cu->id], $request->user());

        return response()->json(['data' => $o + [
            'participant' => ['name' => $enrollment->member?->user?->name ?? $enrollment->participant_name, 'memberNumber' => $enrollment->member?->member_number,
                'employeeId' => $employment?->employee_id, 'position' => $employment?->position, 'unit' => $enrollment->sponsorUnit?->label(), 'employment' => $employment?->status],
            'certificate' => ($c = $enrollment->certificate) ? ['status' => $c->status, 'number' => $c->status === 'issued' ? $c->number : null, 'issuedAt' => IdDate::long($c->issued_at)] : null,
        ]]);
    }

    /** GET /corporate/organizations/{org}/enrollments-export — CSV sesuai scope & izin yang sama dengan dashboard (§20.21). */
    public function export(Request $request, Organization $organization): StreamedResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('export'), 403, 'Peran Anda tidak dapat mengekspor data.');
        $rows = $this->access->listableEnrollments($cu)->with(['member.user:id,name', 'schedule.package:id,title', 'certificate:id,enrollment_id,status,number', 'sponsorUnit:id,name,code'])->orderBy('id')->get();
        $active = EmploymentRelationship::query()->active()->where('organization_id', $organization->id)->get()->keyBy('member_id');
        AuditLog::record('corporate.exported', $organization, null, ['corporate_user_id' => $cu->id, 'rows' => $rows->count(), 'type' => 'participants'], $request->user());

        return response()->streamDownload(function () use ($rows, $cu, $active) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nama', 'Nomor member', 'Employee ID', 'Unit', 'Program', 'Kelas', 'Status', 'Kehadiran (%)', 'Status sertifikat', 'Status karyawan']);
            foreach ($rows as $e) {
                $r = $this->enrollmentRow($cu, $e, $e->member_id ? $active->get($e->member_id) : null);
                fputcsv($out, [$r['name'], $r['memberNumber'], $r['employeeId'], $r['unit'], $r['program'], $r['schedule'], $r['status'], $r['attendancePercent'], $r['certificateStatus'], $r['employment']]);
            }
            fclose($out);
        }, "peserta-{$organization->code}-".now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /corporate/organizations/{org}/certificates — status sertifikat dari enrollment bersponsor saja (§11, §20.11). */
    public function certificates(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);
        $rows = $this->access->listableEnrollments($cu)->whereHas('certificates')->with(['member.user:id,name', 'schedule.package:id,title', 'certificate'])->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (Enrollment $e) => [
            'enrollmentId' => $e->id,
            'name' => $e->member?->user?->name ?? $e->participant_name,
            'program' => $e->schedule?->package?->title,
            'schedule' => $e->schedule?->code,
            'status' => $e->certificate?->status,
            'number' => $e->certificate?->status === 'issued' ? $e->certificate->number : null,
            'issuedAt' => IdDate::long($e->certificate?->issued_at),
        ])->values()]);
    }

    /** GET /corporate/organizations/{org}/employees — karyawan aktif (untuk memilih peserta) dalam batas unit. */
    public function employees(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('manage_participants') || $cu->allows('view_progress'), 403);
        $rows = $this->employeesQuery($cu)->with(['member.user:id,name,email', 'unit'])
            ->when($request->filled('q'), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('employee_id', 'like', '%'.$request->string('q').'%')
                ->orWhereHas('member.user', fn (Builder $u) => $u->where('name', 'like', '%'.$request->string('q').'%'))))
            ->orderBy('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (EmploymentRelationship $e) => [
            'id' => $e->id, 'name' => $e->member?->user?->name, 'employeeId' => $e->employee_id, 'position' => $e->position, 'unit' => $e->unit?->label(), 'since' => IdDate::long($e->start_date),
        ])->values()]);
    }

    /** GET /corporate/organizations/{org}/units */
    public function units(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);
        $scope = $cu->unitScope();

        return response()->json(['data' => OrganizationUnit::query()->where('organization_id', $organization->id)->where('is_active', true)
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('id', $scope))->orderBy('name')->get()
            ->map(fn (OrganizationUnit $u) => ['id' => $u->id, 'name' => $u->name, 'code' => $u->code, 'type' => $u->type, 'label' => $u->label()])->values()]);
    }

    // ---- pengguna perusahaan (owner) ----

    public function users(Request $request, Organization $organization): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('manage_users'), 403, 'Hanya Corporate Owner yang mengelola pengguna perusahaan.');

        return response()->json(['data' => CorporateUser::query()->where('organization_id', $organization->id)->with(['user:id,name,email', 'unit'])->orderBy('status')->orderBy('id')->get()
            ->map(fn (CorporateUser $u) => ['id' => $u->id, 'name' => $u->user?->name, 'email' => $u->user?->email, 'role' => $u->role, 'roleLabel' => CorporateUser::ROLES[$u->role] ?? $u->role,
                'unitId' => $u->unit_id, 'unit' => $u->unit?->label(), 'status' => $u->status, 'isMe' => $u->id === $cu->id])->values()]);
    }

    public function addUser(Request $request, Organization $organization, OrganizationService $orgs): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('manage_users'), 403);
        $data = $request->validate(['email' => ['required', 'email'], 'role' => ['required', Rule::in(array_keys(CorporateUser::ROLES))], 'unit_id' => ['nullable', 'integer']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Belum ada akun dengan email ini. Minta yang bersangkutan mendaftar di website lalu tambahkan lagi.']);
        }
        $new = $orgs->addCorporateUser($organization, $user, $data['role'], $this->unit($organization, $data['unit_id'] ?? null), $request->user());

        return response()->json(['data' => ['id' => $new->id]], 201);
    }

    public function updateUser(Request $request, Organization $organization, CorporateUser $corporateUser, OrganizationService $orgs): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('manage_users') && $corporateUser->organization_id === $organization->id, 404);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(CorporateUser::ROLES))], 'unit_id' => ['nullable', 'integer']]);
        if ($corporateUser->id === $cu->id) {
            throw ValidationException::withMessages(['role' => 'Anda tidak bisa mengubah peran sendiri.']);
        }
        $this->keepOneOwner($corporateUser, $data['role']);
        $orgs->changeRole($corporateUser, $data['role'], $this->unit($organization, $data['unit_id'] ?? null), $request->user());

        return response()->json(['ok' => true]);
    }

    public function disableUser(Request $request, Organization $organization, CorporateUser $corporateUser, OrganizationService $orgs): JsonResponse
    {
        $cu = $this->member($request, $organization);
        abort_unless($cu->allows('manage_users') && $corporateUser->organization_id === $organization->id, 404);
        if ($corporateUser->id === $cu->id) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa mencabut akses sendiri.']);
        }
        $this->keepOneOwner($corporateUser, null);
        $orgs->disableCorporateUser($corporateUser, 'Dicabut oleh Corporate Owner', $request->user());
        $corporateUser->user?->tokens()->where('name', 'like', 'corporate%')->delete();

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------------

    public function member(Request $request, Organization $organization): CorporateUser
    {
        Gate::authorize('viewCorporate', $organization);
        $cu = $this->access->membership($request->user(), $organization);
        $cu->setRelation('organization', $organization);

        return $cu;
    }

    public function requestsQuery(CorporateUser $cu): Builder
    {
        return $this->reporting->requestsQuery($cu);
    }

    private function employeesQuery(CorporateUser $cu): Builder
    {
        return $this->reporting->employeesQuery($cu);
    }

    private function enrollmentRow(CorporateUser $cu, Enrollment $e, ?EmploymentRelationship $employment): array
    {
        $progress = $this->access->canViewProgress($cu, $e);

        return [
            'id' => $e->id,
            'name' => $e->member?->user?->name ?? $e->participant_name,
            'memberNumber' => $e->member?->member_number,
            'employeeId' => $employment?->employee_id,
            'employment' => $this->reporting->employmentStatus($e, $employment),
            'program' => $e->schedule?->package?->title ?? $e->schedule?->title,
            'schedule' => $e->schedule?->code,
            'dates' => $e->schedule ? IdDate::long($e->schedule->start_date).' – '.IdDate::long($e->schedule->end_date) : null,
            'unit' => $e->sponsorUnit?->label(),
            'status' => $e->status,
            'attendancePercent' => $progress ? $e->attendancePercent() : null,
            'progressVisible' => $progress,
            'certificateStatus' => $this->access->canViewCertificateStatus($cu, $e) ? $e->certificate?->status : null,
        ];
    }

    private function unit(Organization $org, mixed $id): ?OrganizationUnit
    {
        if (! $id) {
            return null;
        }
        $u = OrganizationUnit::query()->find($id);
        if (! $u || $u->organization_id !== $org->id) {
            throw ValidationException::withMessages(['unit_id' => 'Unit bukan milik organisasi ini.']);
        }

        return $u;
    }

    private function keepOneOwner(CorporateUser $target, ?string $newRole): void
    {
        if ($target->role === 'owner' && $newRole !== 'owner'
            && CorporateUser::query()->active()->where('organization_id', $target->organization_id)->where('role', 'owner')->whereKeyNot($target->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'Organisasi harus memiliki minimal satu Corporate Owner aktif.']);
        }
    }
}
