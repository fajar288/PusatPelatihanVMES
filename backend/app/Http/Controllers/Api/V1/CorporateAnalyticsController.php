<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\Organization;
use App\Services\CompetencyGapService;
use App\Services\CorporateAnalyticsService;
use App\Services\CorporateReporting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Analitik perusahaan (§20.15) & competency gap (§20.16) di Corporate Portal (Roadmap 5F).
 * Organisasi diverifikasi lewat CorporateController::member (keanggotaan aktif, organisasi lain → 404).
 * Nama peserta hanya untuk peran view_progress; biaya hanya untuk view_finance; target kompetensi diubah oleh
 * peran manage_competency tanpa batas unit (target berlaku untuk seluruh organisasi). Perubahan target diaudit.
 */
class CorporateAnalyticsController extends Controller
{
    public function __construct(private CorporateController $corporate) {}

    /** GET /corporate/organizations/{org}/analytics?from=&to= */
    public function analytics(Request $request, Organization $organization, CorporateAnalyticsService $analytics): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $cu = $this->corporate->member($request, $organization);
        $period = CorporateAnalyticsService::period($request->query('from'), $request->query('to'));
        $data = $analytics->build($cu, $period['from'], $period['to']);
        AuditLog::record('corporate.analytics_viewed', $organization, null, ['corporate_user_id' => $cu->id] + $period, $request->user());

        return response()->json(['data' => $data]);
    }

    /** GET /corporate/organizations/{org}/competencies: katalog, target per posisi, matriks gap. */
    public function competencies(Request $request, Organization $organization, CompetencyGapService $gaps, CorporateReporting $reporting): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        $matrix = $gaps->matrix($cu);
        $names = $cu->allows('view_progress');
        if ($names) {
            AuditLog::record('corporate.competency_matrix_viewed', $organization, null, ['corporate_user_id' => $cu->id, 'rows' => count($matrix['rows'])], $request->user());
        }

        return response()->json(['data' => [
            'canManage' => $this->canManage($cu),
            'catalog' => Competency::query()->active()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Competency $c) => ['code' => $c->code, 'name' => $c->name, 'category' => $c->category, 'description' => $c->description])->values(),
            'targets' => CompetencyTarget::query()->where('organization_id', $organization->id)->with('competency:id,code,name')
                ->orderBy('position')->get()
                ->map(fn (CompetencyTarget $t) => ['id' => $t->id, 'position' => $t->position, 'competency' => $t->competency?->code, 'competencyName' => $t->competency?->name, 'requiredLevel' => $t->required_level])->values(),
            'positions' => $names || $cu->allows('manage_participants')
                ? $reporting->employeesQuery($cu)->whereNotNull('position')->distinct()->orderBy('position')->pluck('position')->values()
                : [],
            'employees' => $matrix['employees'],
            'employeesWithTargets' => $matrix['employees_with_targets'],
            'summary' => $matrix['summary'],
            'rows' => $names ? collect($matrix['rows'])->map(fn ($r) => [
                'employmentId' => $r['employment_id'], 'name' => $r['name'], 'employeeId' => $r['employee_id'], 'position' => $r['position'], 'unit' => $r['unit'],
                'competency' => $r['competency_code'], 'competencyName' => $r['competency'], 'required' => $r['required'], 'actual' => $r['actual'],
                'source' => $r['source'], 'gap' => $r['gap'], 'met' => $r['met'], 'recommendation' => $r['recommendation'],
            ])->values() : null,
        ]]);
    }

    /** POST /corporate/organizations/{org}/competency-targets: tambah/ubah target (posisi + kompetensi unik). */
    public function storeTarget(Request $request, Organization $organization, CompetencyGapService $gaps): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        abort_unless($this->canManage($cu), 403, 'Peran Anda tidak dapat mengatur target kompetensi.');
        if (is_string($request->input('competency'))) {
            $request->merge(['competency' => strtoupper(trim($request->input('competency')))]);
        }
        $data = $request->validate([
            'position' => ['required', 'string', 'max:150'],
            'competency' => ['required', 'string', Rule::exists('competencies', 'code')->where('is_active', true)],
            'required_level' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $competency = Competency::query()->where('code', $data['competency'])->firstOrFail();
        $t = $gaps->setTarget($organization, $data['position'], $competency, (int) $data['required_level'], $request->user());

        return response()->json(['data' => ['id' => $t->id, 'position' => $t->position, 'competency' => $competency->code, 'competencyName' => $competency->name, 'requiredLevel' => $t->required_level]], $t->wasRecentlyCreated ? 201 : 200);
    }

    /** DELETE /corporate/organizations/{org}/competency-targets/{id} */
    public function destroyTarget(Request $request, Organization $organization, CompetencyTarget $competencyTarget, CompetencyGapService $gaps): JsonResponse
    {
        $cu = $this->corporate->member($request, $organization);
        abort_unless($competencyTarget->organization_id === $organization->id, 404);
        abort_unless($this->canManage($cu), 403, 'Peran Anda tidak dapat mengatur target kompetensi.');
        $gaps->deleteTarget($competencyTarget, $request->user());

        return response()->json(['ok' => true]);
    }

    private function canManage(CorporateUser $cu): bool
    {
        return $cu->allows('manage_competency') && $cu->unitScope() === null;
    }
}
