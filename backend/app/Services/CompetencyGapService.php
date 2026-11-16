<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Competency gap dasar (PRD Roadmap 5 §4.6 "Competency Matrix", §8.2, §20.15, §20.16).
 *
 * Required = target level per posisi organisasi (competency_targets, posisi dicocokkan dengan employment aktif).
 * Actual   = bukti dari enrollment yang DISPONSORI organisasi ini saja (batas privasi §20.11 - hasil training mandiri
 *            atau sponsor lain tidak pernah dipakai):
 *            1) assessment: skor kriteria rubrik yang ditautkan ke kompetensi pada penilaian TERVALIDASI terbaru (skor/maks × 100);
 *            2) training: bila tidak ada bukti assessment, level tertinggi dari program yang LULUS (package_competencies.level).
 * Gap      = required − actual (tanpa bukti → gap = required). Rekomendasi = program terbit yang membangun kompetensi itu
 *            dengan level ≥ required terendah (atau level tertinggi bila tidak ada), belum pernah diluluskan peserta lewat sponsor ini.
 */
class CompetencyGapService
{
    public function __construct(private CorporateReporting $reporting) {}

    /** Matriks untuk pengguna perusahaan (batas unit mengikuti pengguna). @return array<string, mixed> */
    public function matrix(CorporateUser $cu): array
    {
        $employees = $this->reporting->employeesQuery($cu)->with(['member.user:id,name', 'unit:id,name,code'])->get();

        return $this->build($cu->organization, $employees);
    }

    /** Matriks untuk seluruh organisasi (admin VMES). @return array<string, mixed> */
    public function forOrganization(Organization $org): array
    {
        $employees = EmploymentRelationship::query()->where('organization_id', $org->id)->active()->with(['member.user:id,name', 'unit:id,name,code'])->get();

        return $this->build($org, $employees);
    }

    /** @return array<string, mixed> */
    private function build(Organization $org, Collection $employees): array
    {
        $targets = CompetencyTarget::query()->where('organization_id', $org->id)
            ->whereHas('competency', fn ($q) => $q->active())->with('competency')->get()->groupBy('position_key');
        $covered = $employees->filter(fn (EmploymentRelationship $e) => $targets->has(CompetencyTarget::key($e->position)));
        $memberIds = $covered->pluck('member_id')->unique()->values();
        $competencyIds = $targets->flatten()->pluck('competency_id')->unique()->values();
        [$assessed, $trained, $completedPackages] = $this->evidence($org->id, $memberIds, $competencyIds);
        $catalog = $this->catalog($competencyIds);

        $rows = [];
        foreach ($covered as $emp) {
            foreach ($targets[CompetencyTarget::key($emp->position)] as $t) {
                $key = $emp->member_id.':'.$t->competency_id;
                $actual = $assessed[$key] ?? $trained[$key] ?? null;
                $source = isset($assessed[$key]) ? 'assessment' : (isset($trained[$key]) ? 'training' : null);
                $gap = $t->required_level - (int) round($actual ?? 0);
                $rows[] = [
                    'employment_id' => $emp->id,
                    'member_id' => $emp->member_id,
                    'name' => $emp->member?->user?->name,
                    'employee_id' => $emp->employee_id,
                    'position' => $emp->position,
                    'unit' => $emp->unit?->label(),
                    'competency_id' => $t->competency_id,
                    'competency' => $t->competency->name,
                    'competency_code' => $t->competency->code,
                    'required' => $t->required_level,
                    'actual' => $actual === null ? null : (int) round($actual),
                    'source' => $source,
                    'gap' => $gap,
                    'met' => $gap <= 0,
                    'recommendation' => $gap > 0 ? $this->recommend($catalog[$t->competency_id] ?? collect(), $t->required_level, $completedPackages[$emp->member_id] ?? []) : null,
                ];
            }
        }

        $summary = collect($rows)->groupBy('competency_id')->map(fn (Collection $g) => [
            'competency_id' => $g->first()['competency_id'],
            'competency' => $g->first()['competency'],
            'code' => $g->first()['competency_code'],
            'employees' => $g->count(),
            'met' => $g->where('met', true)->count(),
            'coverage' => $g->count() > 0 ? round($g->where('met', true)->count() / $g->count() * 100, 1) : null,
            'no_evidence' => $g->whereNull('actual')->count(),
            'avg_gap' => round((float) $g->avg(fn ($r) => max(0, $r['gap'])), 1),
        ])->sortByDesc('avg_gap')->values()->all();

        return [
            'rows' => $rows,
            'summary' => $summary,
            'employees' => $employees->count(),
            'employees_with_targets' => $covered->count(),
            'positions' => $targets->map(fn ($g) => $g->first()->position)->values()->all(),
        ];
    }

    /**
     * Bukti kompetensi per (member:kompetensi) dari enrollment bersponsor organisasi.
     *
     * @return array{0: array<string, float>, 1: array<string, int>, 2: array<int, list<int>>}
     */
    private function evidence(int $orgId, Collection $memberIds, Collection $competencyIds): array
    {
        if ($memberIds->isEmpty() || $competencyIds->isEmpty()) {
            return [[], [], []];
        }
        $sponsored = fn ($q) => $q->where('enrollments.type', Enrollment::TYPE_CORPORATE)->where('enrollments.sponsor_organization_id', $orgId)
            ->whereIn('enrollments.member_id', $memberIds);

        // 1) assessment tervalidasi terbaru per member × kompetensi
        $scores = DB::table('assessment_scores')
            ->join('competency_assessments', 'competency_assessments.id', '=', 'assessment_scores.assessment_id')
            ->join('enrollments', 'enrollments.id', '=', 'competency_assessments.enrollment_id')
            ->join('rubric_criteria', 'rubric_criteria.id', '=', 'assessment_scores.criterion_id')
            ->where('competency_assessments.status', 'validated')
            ->whereNotNull('assessment_scores.score')
            ->whereIn('rubric_criteria.competency_id', $competencyIds)
            ->where($sponsored)
            ->orderBy('competency_assessments.validated_at')->orderBy('competency_assessments.id')
            ->get(['enrollments.member_id', 'rubric_criteria.competency_id', 'competency_assessments.id as assessment_id', 'assessment_scores.score', 'rubric_criteria.max_score']);
        $assessed = [];
        foreach ($scores->groupBy(fn ($r) => $r->member_id.':'.$r->competency_id) as $key => $g) {
            $latest = $g->where('assessment_id', $g->last()->assessment_id);   // beberapa kriteria → rata-rata dalam penilaian terbaru
            $assessed[$key] = round((float) $latest->avg(fn ($r) => (float) $r->score / max(1, (int) $r->max_score) * 100), 1);
        }

        // 2) program yang diluluskan
        $done = DB::table('enrollments')->join('training_schedules', 'training_schedules.id', '=', 'enrollments.schedule_id')
            ->where('enrollments.status', 'completed')->where($sponsored)
            ->get(['enrollments.member_id', 'training_schedules.package_id']);
        $levels = DB::table('package_competencies')->whereIn('package_id', $done->pluck('package_id')->unique())->whereIn('competency_id', $competencyIds)->get();
        $trained = [];
        foreach ($done as $d) {
            foreach ($levels->where('package_id', $d->package_id) as $l) {
                $key = $d->member_id.':'.$l->competency_id;
                $trained[$key] = max($trained[$key] ?? 0, (int) $l->level);
            }
        }
        $completed = $done->groupBy('member_id')->map(fn ($g) => $g->pluck('package_id')->map(fn ($v) => (int) $v)->unique()->values()->all())->all();

        return [$assessed, $trained, $completed];
    }

    /** Program terbit per kompetensi (untuk rekomendasi). @return Collection<int, Collection> */
    private function catalog(Collection $competencyIds): Collection
    {
        return Competency::query()->whereIn('id', $competencyIds)
            ->with(['packages' => fn ($q) => $q->where('training_packages.status', 'published')->select('training_packages.id', 'training_packages.slug', 'training_packages.title')])
            ->get()->mapWithKeys(fn (Competency $c) => [$c->id => $c->packages]);
    }

    /** @param  list<int>  $completed */
    private function recommend(Collection $packages, int $required, array $completed): ?array
    {
        $open = $packages->reject(fn ($p) => in_array($p->id, $completed, true));
        $pick = $open->filter(fn ($p) => (int) $p->pivot->level >= $required)->sortBy(fn ($p) => (int) $p->pivot->level)->first()
            ?? $open->sortByDesc(fn ($p) => (int) $p->pivot->level)->first();

        return $pick ? ['slug' => $pick->slug, 'title' => $pick->title, 'level' => (int) $pick->pivot->level] : null;
    }

    // ---------------------------------------------------------------- target per posisi

    public function setTarget(Organization $org, string $position, Competency $competency, int $level, ?User $actor): CompetencyTarget
    {
        $position = trim($position);
        if ($position === '' || mb_strlen($position) > 150) {
            throw ValidationException::withMessages(['position' => 'Nama posisi wajib diisi (maks. 150 karakter).']);
        }
        if ($level < 1 || $level > 100) {
            throw ValidationException::withMessages(['required_level' => 'Level target harus 1-100.']);
        }
        if (! $competency->is_active) {
            throw ValidationException::withMessages(['competency' => 'Kompetensi tidak aktif.']);
        }
        $target = CompetencyTarget::query()->firstOrNew(['organization_id' => $org->id, 'position_key' => CompetencyTarget::key($position), 'competency_id' => $competency->id]);
        $before = $target->exists ? ['position' => $target->position, 'required_level' => $target->required_level] : null;
        $target->fill(['position' => $position, 'required_level' => $level, 'updated_by' => $actor?->id]);
        $target->created_by ??= $actor?->id;
        $target->save();
        AuditLog::record($before ? 'competency_target.updated' : 'competency_target.created', $target, $before,
            ['organization_id' => $org->id, 'position' => $position, 'competency' => $competency->code, 'required_level' => $level], $actor);

        return $target;
    }

    public function deleteTarget(CompetencyTarget $target, ?User $actor): void
    {
        AuditLog::record('competency_target.deleted', $target, ['position' => $target->position, 'competency_id' => $target->competency_id, 'required_level' => $target->required_level], null, $actor);
        $target->delete();
    }
}
