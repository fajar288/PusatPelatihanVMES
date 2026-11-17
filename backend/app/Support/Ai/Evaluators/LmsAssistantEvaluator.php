<?php

namespace App\Support\Ai\Evaluators;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiUsageRecord;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Services\AiLearningAssistantService;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Asisten Belajar (90 hari terakhir):
 * - kualitas jawaban = persentase "Membantu" dari umpan balik peserta yang bertanya;
 * - cakupan sumber = persentase jawaban model yang membawa kutipan dari materi;
 * - unresolved rate = persentase pertanyaan yang tidak terjawab dari materi (termasuk panggilan model yang gagal).
 * Baseline: pertanyaan peserta di forum diskusi kelas pada periode yang sama (berapa yang dijawab trainer dan berapa lama).
 * Semua rasio berupa pecahan 0..1 (konvensi Metrics::rate); ai:evaluate menampilkannya sebagai persen.
 */
class LmsAssistantEvaluator implements Evaluator
{
    public const WINDOW_DAYS = 90;

    public const MIN_FEEDBACK = 30;

    public function evaluate(): array
    {
        $from = now()->subDays(self::WINDOW_DAYS);
        $items = AiInsight::query()->where('use_case', AiLearningAssistantService::USE_CASE)->where('created_at', '>=', $from)->get(['id', 'subject_id', 'output']);
        $failed = AiUsageRecord::query()->where('use_case', AiLearningAssistantService::USE_CASE)->where('created_at', '>=', $from)
            ->whereIn('status', ['refused', 'error'])->count();

        $resolved = $items->filter(fn (AiInsight $i) => (bool) ($i->output['resolved'] ?? false))->count();
        $reasons = $items->countBy(fn (AiInsight $i) => (string) ($i->output['reason'] ?? 'answered'));
        // Jawaban yang benar-benar dibuat model (tanpa kasus "belum ada materi teks" yang tidak memanggil model).
        $modelAnswers = $items->count() - ($reasons['no_material'] ?? 0);
        $asked = $items->count() + $failed;

        // Umpan balik hanya dari peserta pemilik enrollment (bukan staf yang meninjau).
        // Aturan kepemilikan sama dengan akses insight (Enrollment::ownedBy).
        $subjectOf = $items->pluck('subject_id', 'id');
        $feedback = AiInsightFeedback::query()->whereIn('ai_insight_id', $items->pluck('id'))->with('user')->get()
            ->filter(fn (AiInsightFeedback $f) => $f->user !== null
                && Enrollment::query()->ownedBy($f->user)->whereKey($subjectOf[$f->ai_insight_id])->exists());
        $helpful = $feedback->where('helpful', true)->count();

        $forum = $this->forumBaseline($from);
        $unresolvedRate = $asked > 0 ? round(($asked - $resolved) / $asked, 4) : null;

        $notes = [];
        if ($asked === 0) {
            $notes[] = 'Belum ada pertanyaan ke Asisten Belajar, metrik belum bisa dihitung.';
        }
        if ($feedback->count() < self::MIN_FEEDBACK) {
            $notes[] = 'Umpan balik peserta baru '.$feedback->count().' dari minimal '.self::MIN_FEEDBACK.', kualitas jawaban belum dapat dinilai.';
        }
        if ($forum['questions'] === 0) {
            $notes[] = 'Belum ada pertanyaan peserta di forum diskusi pada periode yang sama, pembanding belum tersedia.';
        }

        return [
            'metrics' => [
                'window_days' => self::WINDOW_DAYS,
                'questions' => $asked,
                'resolved' => $resolved,
                'unresolved_rate' => $unresolvedRate,
                'not_in_material' => (int) ($reasons['not_in_material'] ?? 0),
                'uncited_hidden' => (int) ($reasons['uncited'] ?? 0),
                'no_material' => (int) ($reasons['no_material'] ?? 0),
                'model_failures' => $failed,
                'citation_coverage' => $modelAnswers > 0 ? round($resolved / $modelAnswers, 4) : null,
                'avg_sources_per_answer' => $resolved > 0
                    ? round($items->sum(fn (AiInsight $i) => count((array) ($i->output['sources'] ?? []))) / $resolved, 2) : null,
                'feedback' => $feedback->count(),
                'helpful_rate' => $feedback->count() > 0 ? round($helpful / $feedback->count(), 4) : null,
            ],
            'baseline' => ['description' => 'Pertanyaan peserta lewat forum diskusi kelas'] + $forum,
            'notes' => $notes,
        ];
    }

    /** @return array{questions:int,answered_by_trainer:int,answered_rate:?float,median_hours_to_answer:?float} */
    private function forumBaseline(DateTimeInterface $from): array
    {
        $questions = DiscussionPost::query()->whereNull('parent_id')->where('is_trainer', false)->where('created_at', '>=', $from)->get(['id', 'created_at']);
        $firstReply = DiscussionPost::query()->whereIn('parent_id', $questions->pluck('id'))->where('is_trainer', true)
            ->selectRaw('parent_id, MIN(created_at) AS first_at')->groupBy('parent_id')->pluck('first_at', 'parent_id');
        $hours = $questions->filter(fn ($q) => isset($firstReply[$q->id]))
            ->map(fn ($q) => max(0, $q->created_at->diffInMinutes(Carbon::parse($firstReply[$q->id])) / 60))->sort()->values();
        $n = $hours->count();

        return [
            'questions' => $questions->count(),
            'answered_by_trainer' => $n,
            'answered_rate' => $questions->count() > 0 ? round($n / $questions->count(), 4) : null,
            'median_hours_to_answer' => $n > 0 ? round($n % 2 ? $hours[intdiv($n, 2)] : ($hours[$n / 2 - 1] + $hours[$n / 2]) / 2, 1) : null,
        ];
    }
}
