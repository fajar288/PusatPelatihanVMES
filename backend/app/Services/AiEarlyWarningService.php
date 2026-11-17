<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiModelVersion;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Ai\DataReference;
use App\Support\Ai\EarlyWarning\BaselineRules;
use App\Support\Ai\EarlyWarning\FeatureExtractor;
use App\Support\Ai\EarlyWarning\RiskModel;
use App\Support\Ai\Metrics;
use App\Support\Notify\Recipient;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Early warning peserta berisiko (statistik, tanpa LLM).
 *
 * - run(): dijalankan harian; skor 0-100 + alasan untuk setiap peserta aktif disimpan sebagai insight (use case
 *   early_warning, langsung approved karena hanya tampil ke staf, trainer, dan perusahaan sponsor). Skor sebelumnya
 *   untuk peserta yang sama ditandai kedaluwarsa. Peserta dengan skor di atas ambang memicu notifikasi ke trainer
 *   kelas dan perusahaan sponsor lewat Notification Engine, dengan kunci dedupe yang SAMA dengan notifications:risk
 *   sehingga satu orang tidak menerima peringatan ganda untuk peserta yang sama dalam satu periode. Peserta sendiri
 *   tidak menerima skor AI (peringatan untuk peserta tetap dari aturan notifications:risk).
 * - calibrate(): menyesuaikan bobot dari kelas yang sudah selesai, diuji pada data terbaru (holdout) dan dibandingkan
 *   dengan baseline aturan; versi baru hanya aktif bila tidak kalah dari baseline.
 * - Tidak ada yang ditulis ke tabel resmi: hanya ai_insights, ai_model_versions, dan pengiriman notifikasi.
 */
class AiEarlyWarningService
{
    public const USE_CASE = 'early_warning';

    public const LEVELS = ['high' => 'Risiko tinggi', 'medium' => 'Risiko sedang', 'low' => 'Risiko rendah'];

    /** Sumbangan logit minimum agar sebuah faktor disebut sebagai alasan. */
    private const REASON_MIN = 0.25;

    public function __construct(
        private readonly FeatureExtractor $features,
        private readonly AiGovernance $governance,
        private readonly AiInsightService $insights,
        private readonly NotificationCenter $center,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function threshold(): int
    {
        return max(1, min(99, (int) config('ai.use_cases.early_warning.threshold', 50)));
    }

    public function model(): RiskModel
    {
        $v = AiModelVersion::active(self::USE_CASE);

        return $v ? RiskModel::fromParams($v->params, $v->version) : RiskModel::prior();
    }

    /** @return array{available: bool, label: string, reason: ?string} */
    public function status(): array
    {
        $s = $this->governance->status(self::USE_CASE);

        return ['available' => $s['available'], 'label' => $s['available'] ? 'Aktif' : 'Belum tersedia', 'reason' => $s['reason_label']];
    }

    public function level(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= $this->threshold() ? 'medium' : 'low');
    }

    /**
     * Skor harian semua peserta aktif. Tidak melakukan apa pun bila use case belum tersedia.
     *
     * @return array{status: string, reason: ?string, scored: int, flagged: int, notified: int, model: ?string}
     */
    public function run(bool $notify = true): array
    {
        $status = $this->governance->status(self::USE_CASE);
        if (! $status['available']) {
            return ['status' => 'unavailable', 'reason' => $status['reason_label'], 'scored' => 0, 'flagged' => 0, 'notified' => 0, 'model' => null];
        }
        $model = $this->model();
        $rows = $this->features->active();
        $enrollments = Enrollment::query()->whereIn('id', $rows->pluck('enrollment_id'))->with('schedule.package')->get()->keyBy('id');
        $flagged = 0;
        $notified = 0;
        foreach ($rows as $row) {
            $e = $enrollments->get($row['enrollment_id']);
            if (! $e) {
                continue;
            }
            $insight = $this->record($e, $row, $model);
            if ($insight->score >= $this->threshold()) {
                $flagged++;
                if ($notify && $this->notify($e, $insight)) {
                    $notified++;
                }
            }
        }

        return ['status' => 'ok', 'reason' => null, 'scored' => $rows->count(), 'flagged' => $flagged, 'notified' => $notified, 'model' => $model->version];
    }

    /** @param  array<string, mixed>  $row */
    private function record(Enrollment $e, array $row, RiskModel $model): AiInsight
    {
        $pred = $model->predict($row['x']);
        $reasons = [];
        foreach ($pred['contributions'] as $feature => $c) {
            if ($c >= self::REASON_MIN && count($reasons) < 3) {
                $reasons[] = ['factor' => $feature, 'label' => RiskModel::LABELS[$feature], 'text' => FeatureExtractor::reason($feature, $row['raw']), 'weight' => round($c, 2)];
            }
        }
        $level = $this->level($pred['score']);
        $insight = $this->insights->record(
            self::USE_CASE,
            [
                'score' => $pred['score'],
                'level' => $level,
                'level_label' => self::LEVELS[$level],
                'summary' => self::LEVELS[$level].' (skor '.$pred['score'].'/100)'.($reasons ? ': '.implode('; ', array_column($reasons, 'text')) : ''),
                'reasons' => $reasons,
                'features' => $row['x'],
                'threshold' => $this->threshold(),
            ],
            (new DataReference)->add($e, ['x' => $row['x'], 'raw' => $row['raw']]),
            subject: $e,
            score: (float) $pred['score'],
            confidence: round($pred['probability'], 4),
            organizationId: $row['sponsor_organization_id'],
            modelVersion: $model->version,
            expiresAt: now()->addHours((int) config('ai.use_cases.early_warning.ttl_hours', 48)),
        );
        $this->insights->supersede(self::USE_CASE, $e, $insight->id);

        return $insight;
    }

    /** Notifikasi ke trainer kelas + perusahaan sponsor (kunci dedupe sama dengan notifications:risk). */
    private function notify(Enrollment $e, AiInsight $insight): bool
    {
        if (! config('notifications.risk.enabled', true)) {
            return false;
        }
        try {
            $s = $e->schedule;
            $period = intdiv((int) today()->timestamp, 86400 * max(1, (int) config('notifications.risk.repeat_days', 7)));
            $reasons = 'Skor risiko '.(int) $insight->score.'/100 (dibuat AI)'
                .(($r = $insight->output['reasons'] ?? []) ? ': '.implode('; ', array_column($r, 'text')) : '');
            $url = '/trainer/progres/'.$s->code;
            $trainers = $this->recipients->trainers($s)->map(fn (Recipient $r) => new Recipient($r->audience, $r->user, $r->name, $r->email, $r->phone, $r->organizationId, $r->vars, $url));
            $corporate = $this->recipients->corporate([$e], 'view_progress', true, fn ($org) => '/corporate/'.$org->code.'/peserta/'.$e->id);
            $result = $this->center->publish('progress.risk', $trainers->merge($corporate), [
                'participant' => $e->participant_name, 'program' => $s->title ?? $s->package?->title, 'class' => $s->code, 'reasons' => $reasons,
            ], ['url' => $url, 'subject' => $e, 'actor' => null, 'dedupe' => "progress.risk:{$e->id}:$period"]);

            return $result['deliveries'] > 0;
        } catch (Throwable $ex) {
            report($ex);

            return false;
        }
    }

    /**
     * Skor terbaru yang boleh tampil untuk sekumpulan enrollment (kosong bila use case belum tersedia).
     *
     * @param  iterable<int>  $enrollmentIds
     * @return array<int, array<string, mixed>> enrollment_id => payload
     */
    public function latestFor(iterable $enrollmentIds, ?User $viewer = null, ?int $organizationId = null): array
    {
        $ids = collect($enrollmentIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty() || ! $this->governance->available(self::USE_CASE)) {
            return [];
        }
        $rows = AiInsight::query()->visible()->where('use_case', self::USE_CASE)
            ->where('subject_type', (new Enrollment)->getMorphClass())->whereIn('subject_id', $ids)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderByDesc('id')->get()->unique('subject_id');
        $feedback = $viewer ? AiInsightFeedback::query()->where('user_id', $viewer->id)->whereIn('ai_insight_id', $rows->pluck('id'))->pluck('helpful', 'ai_insight_id') : collect();

        return $rows->mapWithKeys(fn (AiInsight $i) => [(int) $i->subject_id => $this->payload($i, $feedback->get($i->id))])->all();
    }

    /** @return array<string, mixed> */
    public function payload(AiInsight $i, ?bool $helpful = null): array
    {
        $o = (array) $i->output;

        return [
            'insightId' => $i->id,
            'score' => (int) $i->score,
            'level' => $o['level'] ?? $this->level((int) $i->score),
            'levelLabel' => $o['level_label'] ?? self::LEVELS[$this->level((int) $i->score)],
            'reasons' => array_values(array_column($o['reasons'] ?? [], 'text')),
            'threshold' => $o['threshold'] ?? $this->threshold(),
            'model' => $i->model_version,
            'computedAt' => $i->created_at?->toIso8601String(),
            'aiGenerated' => true,
            'myFeedback' => $helpful,
        ];
    }

    // ------------------------------------------------------------------ evaluasi & kalibrasi

    /**
     * Bandingkan model dan baseline aturan pada kelas yang sudah selesai (label: tidak lulus).
     *
     * @param  Collection<int, array<string, mixed>>|null  $samples
     * @return array<string, mixed>
     */
    public function evaluate(?RiskModel $model = null, ?Collection $samples = null): array
    {
        $model ??= $this->model();
        $samples ??= $this->features->finished();
        $actual = $samples->pluck('outcome_failed')->map(fn ($v) => (bool) $v)->all();
        $predicted = $samples->map(fn ($s) => $model->predict($s['x'])['score'] >= $this->threshold())->all();
        $baseline = $samples->map(fn ($s) => BaselineRules::flagged($s['raw'], true))->all();
        $m = Metrics::binary($predicted, $actual);
        $b = Metrics::binary($baseline, $actual);

        return [
            'model_version' => $model->version,
            'threshold' => $this->threshold(),
            'n' => count($actual),
            'positives' => count(array_filter($actual)),
            'model' => $m,
            'baseline' => $b,
            // null = belum dapat dinilai (tidak ada peserta tidak lulus atau F1 model tidak terdefinisi).
            'beats_baseline' => $m['f1'] === null || count(array_filter($actual)) === 0 ? null : ($b['f1'] === null || $m['f1'] >= $b['f1']),
        ];
    }

    /**
     * Kalibrasi bobot: data selesai diurutkan menurut tanggal akhir kelas; 70% terlama untuk melatih, 30% terbaru untuk
     * menguji. Versi baru disimpan dengan metrik holdout dan hanya diaktifkan bila F1-nya tidak kalah dari baseline.
     *
     * @return array<string, mixed>
     */
    public function calibrate(?User $actor = null, bool $force = false): array
    {
        $readiness = app(AiReadiness::class)->report(self::USE_CASE);
        if (! $readiness['ready'] && ! $force) {
            return ['status' => 'insufficient_data', 'readiness' => $readiness];
        }
        $samples = $this->features->finished()->sortBy([['end_date', 'asc'], ['enrollment_id', 'asc']])->values();
        if ($samples->count() < 10 || $samples->where('outcome_failed', true)->isEmpty()) {
            return ['status' => 'insufficient_data', 'readiness' => $readiness];
        }
        $cut = max(1, (int) floor($samples->count() * 0.7));
        $train = $samples->take($cut);
        $test = $samples->slice($cut)->values();
        $version = 'ew-'.now()->format('Ymd-His');
        $fitted = RiskModel::fit($train->map(fn ($s) => ['x' => $s['x'], 'y' => (bool) $s['outcome_failed']])->all(), $version);

        $holdout = $this->evaluate($fitted, $test);
        $prior = $this->evaluate(RiskModel::prior(), $test);
        $activate = $holdout['beats_baseline'] === true;
        if ($activate) {
            AiModelVersion::query()->where('use_case', self::USE_CASE)->update(['is_active' => false]);
        }
        $saved = AiModelVersion::query()->create([
            'use_case' => self::USE_CASE,
            'version' => $version,
            'params' => $fitted->params(),
            'metrics' => ['holdout' => $holdout, 'prior_on_holdout' => $prior, 'train_size' => $train->count(), 'test_size' => $test->count()],
            'training_size' => $train->count(),
            'is_active' => $activate,
            'note' => match ($holdout['beats_baseline']) {
                true => 'Aktif: F1 holdout tidak kalah dari baseline aturan.',
                false => 'Tidak diaktifkan: F1 holdout kalah dari baseline aturan.',
                null => 'Tidak diaktifkan: data holdout belum memuat peserta tidak lulus, belum dapat dinilai.',
            },
            'created_by' => $actor?->id,
        ]);

        return ['status' => $activate ? 'activated' : 'rejected', 'version' => $saved->version, 'holdout' => $holdout, 'prior_on_holdout' => $prior, 'readiness' => $readiness, 'forced' => $force && ! $readiness['ready']];
    }
}
