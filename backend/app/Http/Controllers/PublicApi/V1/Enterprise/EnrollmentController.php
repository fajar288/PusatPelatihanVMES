<?php

namespace App\Http\Controllers\PublicApi\V1\Enterprise;

use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Services\CorporateAccess;
use App\Services\CorporateReporting;
use App\Support\Enterprise\EnterpriseApiPresenter;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * API enterprise — enrollment bersponsor (R3), progres LMS, kehadiran, penilaian (§20.10) & status sertifikat (§20.11).
 * Selalu dibatasi organisasi klien + batas unit pengguna perusahaan yang diwakili + kebijakan exit_access.
 * Self-funded & sponsor lain tidak pernah muncul; id enrollment di luar scope dijawab 404.
 */
class EnrollmentController extends EnterpriseController
{
    private const WITH = ['member.user:id,name', 'schedule.package:id,slug,title', 'certificate', 'sponsorUnit', 'requestSeat.request:id,code'];

    public function __construct(private CorporateAccess $access, private CorporateReporting $reporting) {}

    /** GET /enrollments?status=&schedule=&request=&employee_id=&updated_since= */
    public function index(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, $this->filterRules());
        $q = $this->filtered($this->access->listableEnrollments($cu), $p, $cu)->with(self::WITH)->latest('id');
        $active = $this->activeEmployments($cu);
        $response = $this->paginate($request, $q, fn (Enrollment $e) => $this->row($cu, $e, $active));
        $this->auditAccess($request, 'api.participants_viewed', null, ['count' => count($response->getData(true)['data'])]);

        return $response;
    }

    /** GET /enrollments/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $cu = $this->cu($request);
        $e = $this->access->listableEnrollments($cu)->with(self::WITH)->whereKey($id)->first();
        if (! $e) {
            return $this->notFound('Enrollment tidak ditemukan.');
        }
        $this->auditAccess($request, 'api.participant_viewed', $e);

        return response()->json(['data' => $this->row($cu, $e, $this->activeEmployments($cu))]);
    }

    /** GET /certificates — status sertifikat enrollment bersponsor (nomor hanya bila terbit). */
    public function certificates(Request $request): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, ['status' => ['nullable', Rule::in(array_keys(Certificate::STATUSES))]] + $this->filterRules());
        $q = $this->filtered($this->access->listableEnrollments($cu), array_diff_key($p, ['status' => true]), $cu)
            ->whereHas('certificates', fn (Builder $c) => $c->when($p['status'] ?? null, fn ($w, $s) => $w->where('status', $s)))
            ->with([...self::WITH, 'certificates'])->latest('id');
        if ($since = $p['updated_since'] ?? null) {
            // perubahan sertifikat (terbit/cabut) dihitung dari tabel sertifikat, bukan enrollment
            $q->whereHas('certificates', fn (Builder $c) => $c->where('updated_at', '>=', Carbon::parse($since)));
        }

        // sertifikat terakhir enrollment (sesuai filter status) — termasuk yang dicabut/diganti
        $latest = fn (Enrollment $e) => $e->certificates->when($p['status'] ?? null, fn ($c, $s) => $c->where('status', $s))->sortByDesc('id')->first();

        return $this->paginate($request, $q, applySince: false, map: fn (Enrollment $e) => [
            'enrollment_id' => $e->id,
            'participant' => ['name' => $e->member?->user?->name ?? $e->participant_name, 'member_number' => $e->member?->member_number],
            'program' => $e->schedule?->package?->title,
            'schedule' => EnterpriseApiPresenter::schedule($e->schedule),
        ] + EnterpriseApiPresenter::certificateStatus($latest($e)));
    }

    /** GET /progress — progres LMS & praktik (logbook tervalidasi, tanpa catatan privat trainer). */
    public function progress(Request $request): JsonResponse
    {
        return $this->learningList($request, 'api.progress_viewed', fn (Enrollment $e, array $o) => [
            'learning' => [
                'percent' => $o['learning']['percent'], 'completed' => $o['learning']['completed'], 'total' => $o['learning']['total'],
                'last_seen_at' => $o['learning']['lastSeenAt'],
                'courses' => collect($o['learning']['courses'])->map(fn ($c) => ['slug' => $c['slug'], 'title' => $c['title'], 'percent' => $c['percent'] ?? null, 'completed' => $c['completed'] ?? null, 'total' => $c['total'] ?? null])->all(),
            ],
            'practice' => ['completed' => $o['practice']['completed'], 'partial' => $o['practice']['partial'], 'not_done' => $o['practice']['notDone'], 'logbooks' => $o['practice']['logged']],
            'logbook' => collect($o['notes'])->map(fn ($n) => ['date' => $n['date'], 'topic' => $n['topic'], 'practice_status' => $n['practiceStatus']])->all(),
        ]);
    }

    /** GET /attendance?from=&to= — rekap & rincian kehadiran per sesi. */
    public function attendance(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return $this->learningList($request, 'api.attendance_viewed', function (Enrollment $e, array $o) use ($range) {
            $byId = $e->attendances->keyBy('session_id');
            $sessions = $e->schedule->sessions->sortBy('session_no')
                ->filter(fn ($s) => (! isset($range['from']) || $s->date->gte($range['from'])) && (! isset($range['to']) || $s->date->lte($range['to'])));

            return [
                'attendance' => [
                    'percent' => $o['attendance']['percent'], 'present' => $o['attendance']['present'], 'held' => $o['attendance']['held'], 'total' => $o['attendance']['total'],
                    'sessions' => $sessions->map(fn ($s) => [
                        'session_no' => (int) $s->session_no, 'date' => $s->date->toDateString(), 'topic' => $s->topic,
                        'status' => $byId->get($s->id)?->status, 'checked_at' => $byId->get($s->id)?->checked_at?->toIso8601String(),
                    ])->values()->all(),
                ],
            ];
        });
    }

    /** GET /assessments — nilai kuis/tugas, nilai akhir & penilaian kompetensi TERVALIDASI (tanpa catatan penilai). */
    public function assessments(Request $request): JsonResponse
    {
        return $this->learningList($request, 'api.assessments_viewed', fn (Enrollment $e, array $o) => [
            'final_score' => $e->final_score !== null ? (float) $e->final_score : null,
            'scores' => [
                'quiz_avg' => $o['scores']['quizAvg'], 'quiz_done' => $o['scores']['quizDone'], 'quiz_total' => $o['scores']['quizTotal'],
                'assignment_avg' => $o['scores']['assignmentAvg'], 'assignments_graded' => $o['scores']['assignmentsGraded'],
                'assignments_submitted' => $o['scores']['assignmentsSubmitted'], 'assignments_total' => $o['scores']['assignmentsTotal'],
            ],
            'competency' => is_array($o['competency'] ?? null) ? [
                'status' => $o['competency']['status'], 'percent' => $o['competency']['percent'], 'is_competent' => $o['competency']['isCompetent'],
                'threshold' => $o['competency']['threshold'] ?? null, 'rubric' => $o['competency']['rubric']['name'] ?? null,
                'assessed_at' => $o['competency']['assessedAt'] ?? null, 'validated_at' => $o['competency']['validatedAt'] ?? null,
                'criteria' => collect($o['competency']['criteria'] ?? [])->map(fn ($c) => [
                    'code' => $c['code'] ?? null, 'name' => $c['name'] ?? null, 'competency' => $c['competency'] ?? null, 'weight' => $c['weight'] ?? null,
                    'max_score' => $c['maxScore'] ?? null, 'score' => $c['score'] ?? null, 'level' => $c['levelLabel'] ?? null,
                ])->values()->all(),
            ] : null,
        ]);
    }

    // ---------------------------------------------------------------------

    /**
     * Daftar progres/kehadiran/penilaian: hanya enrollment yang progresnya boleh dibuka (CorporateReporting::progressQuery),
     * maks. 50 per halaman (dihitung dari data LMS, logbook & penilaian).
     */
    private function learningList(Request $request, string $audit, Closure $extra): JsonResponse
    {
        $cu = $this->cu($request);
        $p = $this->listParams($request, $this->filterRules(), 50);
        $q = $this->filtered($this->reporting->progressQuery($cu), $p, $cu)
            ->whereIn('status', ['enrolled', 'attending', 'completed', 'failed'])
            ->with(['member.user:id,name', 'schedule.package:id,slug,title', 'schedule.sessions', 'attendances'])->latest('id');
        $response = $this->paginate($request, $q, function (Enrollment $e) use ($extra) {
            $o = $this->reporting->progress($e);

            return [
                'enrollment_id' => $e->id,
                'status' => $e->status,
                'participant' => ['name' => $e->member?->user?->name ?? $e->participant_name, 'member_number' => $e->member?->member_number],
                'schedule' => EnterpriseApiPresenter::schedule($e->schedule),
            ] + $extra($e, $o);
        }, 25);
        $this->auditAccess($request, $audit, null, ['count' => count($response->getData(true)['data'])]);

        return $response;
    }

    private function filterRules(): array
    {
        return [
            'enrollment' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Enrollment::STATUSES)],
            'schedule' => ['nullable', 'string', 'max:60'],
            'request' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:60'],
        ];
    }

    private function filtered(Builder $q, array $p, CorporateUser $cu): Builder
    {
        return $q
            ->when($p['enrollment'] ?? null, fn (Builder $w, $id) => $w->whereKey($id))
            ->when($p['status'] ?? null, fn (Builder $w, $s) => $w->where('status', $s))
            ->when($p['schedule'] ?? null, fn (Builder $w, $c) => $w->whereHas('schedule', fn (Builder $s) => $s->where('code', $c)))
            ->when($p['request'] ?? null, fn (Builder $w, $c) => $w->whereHas('requestSeat.request', fn (Builder $r) => $r->where('code', $c)))
            ->when($p['unit'] ?? null, fn (Builder $w, $c) => $w->whereHas('sponsorUnit', fn (Builder $u) => $u->where('code', $c)))
            ->when($p['employee_id'] ?? null, fn (Builder $w, $id) => $w->whereIn('member_id', EmploymentRelationship::query()
                ->where('organization_id', $cu->organization_id)->where('employee_id', $id)->select('member_id')));
    }

    /** @return Collection<int, EmploymentRelationship> employment aktif per member_id */
    private function activeEmployments(CorporateUser $cu): Collection
    {
        return EmploymentRelationship::query()->active()->where('organization_id', $cu->organization_id)->get()->keyBy('member_id');
    }

    private function row(CorporateUser $cu, Enrollment $e, Collection $active): array
    {
        $employment = $e->member_id ? $active->get($e->member_id) : null;
        $employment ??= $e->member_id ? EmploymentRelationship::query()->where('organization_id', $cu->organization_id)->where('member_id', $e->member_id)->latest('id')->first() : null;

        return EnterpriseApiPresenter::enrollment(
            $e,
            $this->reporting->employmentStatus($e, $employment?->isActive() ? $employment : null),
            $employment,
            $this->access->canViewProgress($cu, $e),
            $this->access->canViewCertificateStatus($cu, $e),
        );
    }
}
