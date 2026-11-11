<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTrainer;
use App\Http\Controllers\Controller;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\LogbookEntry;
use App\Models\TrainingLogbook;
use App\Models\TrainingSchedule;
use App\Services\MonitoringService;
use App\Support\IdDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Portal trainer — Logbook, Assessment (penilaian kompetensi), Progress overview (PRD §5.9). */
class TrainerMonitoringController extends Controller
{
    use ResolvesTrainer;

    public function __construct(private readonly MonitoringService $monitoring) {}

    private function logbookOf(Request $request, int $id): TrainingLogbook
    {
        $t = $this->trainer($request);
        $l = TrainingLogbook::query()->whereKey($id)->with('schedule')->firstOrFail();
        abort_unless($t->schedules()->whereKey($l->schedule_id)->exists(), 403, 'Logbook ini bukan dari kelas Anda.');

        return $l;
    }

    private function enrollmentOf(TrainingSchedule $s, int $id): Enrollment
    {
        return Enrollment::query()->whereKey($id)->where('schedule_id', $s->id)->with('schedule.package')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function classInfo(TrainingSchedule $s): array
    {
        return ['code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'dateLabel' => IdDate::long($s->start_date), 'endLabel' => IdDate::long($s->end_date), 'status' => $s->status];
    }

    // ---------------------------------------------------------------- logbook

    /** GET /trainer/classes/{code}/logbooks — logbook kelas + sesi & peserta untuk form. */
    public function logbooks(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code)->load('package', 'sessions', 'enrollments');
        $logbooks = $s->logbooks()->with(['entries', 'session', 'trainer', 'validator'])->get();
        $logged = $logbooks->pluck('session_id')->filter()->flip();

        return response()->json(['data' => [
            'class' => $this->classInfo($s),
            'sessions' => $s->sessions->map(fn ($x) => ['id' => $x->id, 'no' => $x->session_no, 'date' => $x->date->toDateString(), 'dateLabel' => IdDate::short($x->date), 'topic' => $x->topic, 'hasLogbook' => $logged->has($x->id)])->values(),
            'participants' => $s->enrollments->sortBy('participant_name')->map(fn ($e) => ['id' => $e->id, 'name' => $e->participant_name, 'email' => $e->participant_email, 'status' => $e->status])->values(),
            'practiceLabels' => LogbookEntry::PRACTICE,
            'logbooks' => $logbooks->map(fn ($l) => MonitoringService::logbook($l))->values(),
        ]]);
    }

    /** POST /trainer/classes/{code}/logbooks — buat logbook (multipart: field + docs[]). */
    public function storeLogbook(Request $request, string $code): JsonResponse
    {
        $t = $this->trainer($request);
        $s = $this->classOf($t, $code);
        [$data, $entries, $files, $remove] = $this->logbookInput($request, $s);
        $l = $this->monitoring->saveLogbook($s, null, $data, $entries, $files, $remove, $request->user(), $t);

        return response()->json(['data' => MonitoringService::logbook($l)], 201);
    }

    /** POST /trainer/logbooks/{id} — ubah logbook (ditolak bila terkunci). */
    public function updateLogbook(Request $request, int $id): JsonResponse
    {
        $t = $this->trainer($request);
        $l = $this->logbookOf($request, $id);
        [$data, $entries, $files, $remove] = $this->logbookInput($request, $l->schedule);
        $l = $this->monitoring->saveLogbook($l->schedule, $l, $data, $entries, $files, $remove, $request->user(), $t);

        return response()->json(['data' => MonitoringService::logbook($l)]);
    }

    /** POST /trainer/logbooks/{id}/validate — validasi & kunci. */
    public function validateLogbook(Request $request, int $id): JsonResponse
    {
        $l = $this->monitoring->validateLogbook($this->logbookOf($request, $id), $request->user());

        return response()->json(['data' => MonitoringService::logbook($l)]);
    }

    /** GET /trainer/logbooks/{id}/docs/{index} — unduh dokumentasi. */
    public function logbookDoc(Request $request, int $id, int $index): StreamedResponse
    {
        $l = $this->logbookOf($request, $id);
        $doc = collect($l->documentation ?? [])->values()->get($index);
        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($doc && ! empty($doc['path']) && $disk->exists($doc['path']), 404);

        return $disk->download($doc['path'], $doc['name'] ?? basename($doc['path']));
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: UploadedFile[], 3: int[]} */
    private function logbookInput(Request $request, TrainingSchedule $s): array
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'integer', Rule::exists('class_sessions', 'id')->where('schedule_id', $s->id)],
            'date' => ['required', 'date'],
            'topic' => ['required', 'string', 'max:255'],
            'activities' => ['nullable', 'string', 'max:5000'],
            'competencies' => ['nullable', 'array'], 'competencies.*' => ['string', 'max:150'],
            'practice' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'obstacles' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'entries' => ['nullable', 'array'],
            'entries.*.enrollment_id' => ['required', 'integer'],
            'entries.*.practice_status' => ['nullable', Rule::in(array_keys(LogbookEntry::PRACTICE))],
            'entries.*.observation' => ['nullable', 'string', 'max:2000'],
            'docs' => ['nullable', 'array', 'max:10'],
            'docs.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,mp4,doc,docx,xls,xlsx,ppt,pptx'],
            'remove_docs' => ['nullable', 'array'], 'remove_docs.*' => ['integer', 'min:0'],
        ]);

        return [$data, $data['entries'] ?? [], $request->file('docs', []), array_map('intval', $data['remove_docs'] ?? [])];
    }

    // ---------------------------------------------------------------- penilaian

    /** GET /trainer/classes/{code}/assessments — rubrik program + penilaian tiap peserta. */
    public function assessments(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code)->load('package', 'enrollments');
        $items = $s->enrollments->sortBy('participant_name')->values()->map(function (Enrollment $e) use ($s) {
            $e->setRelation('schedule', $s);

            return ['participant' => ['id' => $e->id, 'name' => $e->participant_name, 'email' => $e->participant_email, 'status' => $e->status], 'assessment' => MonitoringService::assessment($this->monitoring->assessmentFor($e))];
        });
        $rubric = $items->first()['assessment']['rubric'] ?? null;
        $criteria = $items->first()['assessment']['criteria'] ?? [];

        return response()->json(['data' => [
            'class' => $this->classInfo($s),
            'rubric' => $rubric ? $rubric + ['criteria' => collect($criteria)->map(fn ($c) => array_diff_key($c, array_flip(['score', 'levelLabel', 'note'])))->values(), 'threshold' => $items->first()['assessment']['threshold'] ?? 70] : null,
            'participants' => $items,
        ]]);
    }

    /** POST /trainer/classes/{code}/assessments/{enrollment} — simpan skor; {scores:[{criterion_id,score,note}], summary, recommendation, validate?} */
    public function saveAssessment(Request $request, string $code, int $enrollment): JsonResponse
    {
        $t = $this->trainer($request);
        $s = $this->classOf($t, $code);
        $e = $this->enrollmentOf($s, $enrollment);
        $data = $request->validate([
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.criterion_id' => ['required', 'integer'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0'],
            'scores.*.note' => ['nullable', 'string', 'max:500'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', 'string', 'max:5000'],
            'validate' => ['nullable', 'boolean'],
        ]);
        $a = $this->monitoring->saveAssessment($e, $data['scores'], $data['summary'] ?? null, $data['recommendation'] ?? null, $request->user(), $t);
        if (! empty($data['validate'])) {
            $a = $this->monitoring->validateAssessment($a, $request->user());
        }

        return response()->json(['data' => MonitoringService::assessment($a)]);
    }

    /** POST /trainer/assessments/{id}/validate */
    public function validateAssessment(Request $request, int $id): JsonResponse
    {
        $t = $this->trainer($request);
        $a = CompetencyAssessment::query()->whereKey($id)->with('enrollment')->firstOrFail();
        abort_unless($t->schedules()->whereKey($a->enrollment->schedule_id)->exists(), 403, 'Penilaian ini bukan dari kelas Anda.');

        return response()->json(['data' => MonitoringService::assessment($this->monitoring->validateAssessment($a, $request->user()))]);
    }

    // ---------------------------------------------------------------- progress overview

    /** GET /trainer/classes/{code}/progress — tabel overview seluruh peserta. */
    public function progress(Request $request, string $code): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code)->load('package');

        return response()->json(['data' => ['class' => $this->classInfo($s), 'participants' => $this->monitoring->classOverview($s)]]);
    }

    /** GET /trainer/classes/{code}/progress/{enrollment} — overview lengkap satu peserta. */
    public function participantProgress(Request $request, string $code, int $enrollment): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code);

        return response()->json(['data' => $this->monitoring->overview($this->enrollmentOf($s, $enrollment))]);
    }
}
