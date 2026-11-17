<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTrainer;
use App\Http\Controllers\Controller;
use App\Models\AiInsightFeedback;
use App\Models\SurveyResponse;
use App\Services\AiFeedbackService;
use App\Services\AiGovernance;
use App\Services\SurveyService;
use App\Support\IdDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Survei evaluasi pasca-kelas: diisi peserta di portal member, laporan kelas untuk trainer (tanpa nama peserta). */
class SurveyController extends Controller
{
    use ResolvesTrainer;

    public function __construct(private readonly SurveyService $surveys) {}

    /** GET /me/surveys */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->surveys->forUser($request->user())->map(fn (SurveyResponse $r) => $this->row($r))->values()]);
    }

    /** GET /me/surveys/{id}: pertanyaan + jawaban (hanya milik sendiri). */
    public function show(Request $request, int $id): JsonResponse
    {
        $r = $this->own($request, $id);

        return response()->json(['data' => $this->row($r) + ['questions' => $r->template->questionList(), 'answers' => (object) ($r->answers ?? [])]]);
    }

    /** POST /me/surveys/{id} {answers: {key: nilai}} */
    public function submit(Request $request, int $id): JsonResponse
    {
        $request->validate(['answers' => ['required', 'array']]);
        $r = $this->surveys->submit($this->own($request, $id), (array) $request->input('answers'));

        return response()->json(['data' => $this->row($r->fresh(['schedule.package', 'template']))]);
    }

    /** GET /trainer/classes/{code}/survey: rekap survei kelas + ringkasan AI yang sudah disetujui admin. */
    public function trainerReport(Request $request, string $code, AiFeedbackService $feedback): JsonResponse
    {
        $s = $this->classOf($this->trainer($request), $code)->load('package');
        $report = $this->surveys->classReport($s);
        // Rincian (skor per pertanyaan & komentar) baru dibuka ke trainer bila pengisi cukup banyak agar tidak bisa ditebak.
        $report['detailHidden'] = $report['submitted'] < SurveyService::TRAINER_MIN_RESPONSES;
        $report['minResponses'] = SurveyService::TRAINER_MIN_RESPONSES;
        if ($report['detailHidden']) {
            $report['average'] = null;
            $report['questions'] = array_map(fn ($q) => ['average' => null] + $q, $report['questions']);
            $report['comments'] = [];
        }
        $summary = $feedback->summaryFor($s);

        return response()->json(['data' => [
            'class' => ['code' => $s->code, 'title' => $s->title ?? $s->package?->title, 'dateLabel' => IdDate::long($s->start_date), 'endLabel' => IdDate::long($s->end_date)],
            // Komentar ditampilkan tanpa nama dan tanpa ID respons.
            'report' => ['comments' => array_map(fn ($c) => ['question' => $c['question'], 'text' => $c['text']], $report['comments'])] + $report,
            'summary' => $summary ? [
                'insightId' => $summary->id,
                'aiGenerated' => true,
                'summary' => $summary->output['summary'] ?? '',
                'strengths' => $summary->output['strengths'] ?? [],
                'improvements' => $summary->output['improvements'] ?? [],
                'actions' => $summary->output['actions'] ?? [],
                'computedAt' => $summary->created_at?->toIso8601String(),
                'myFeedback' => ($f = AiInsightFeedback::query()->where('ai_insight_id', $summary->id)->where('user_id', $request->user()->id)->value('helpful')) === null ? null : (bool) $f,
            ] : null,
            'aiStatus' => app(AiGovernance::class)->status(AiFeedbackService::USE_CASE)['available'],
        ]]);
    }

    private function own(Request $request, int $id): SurveyResponse
    {
        $memberId = $request->user()->member?->id;

        return SurveyResponse::query()->whereKey($id)->where('member_id', $memberId ?? 0)->with(['schedule.package', 'template'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(SurveyResponse $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'statusLabel' => SurveyResponse::STATUSES[$r->status] ?? $r->status,
            'class' => ['code' => $r->schedule?->code, 'title' => $r->schedule?->title ?? $r->schedule?->package?->title, 'endLabel' => IdDate::long($r->schedule?->end_date)],
            'invitedAt' => $r->invited_at?->toIso8601String(),
            'submittedAt' => $r->submitted_at?->toIso8601String(),
        ];
    }
}
