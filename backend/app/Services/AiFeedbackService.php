<?php

namespace App\Services;

use App\Models\AiBatch;
use App\Models\AiInsight;
use App\Models\FeedbackLabel;
use App\Models\SurveyResponse;
use App\Models\TrainingSchedule;
use App\Support\Ai\AiResult;
use App\Support\Ai\DataReference;
use App\Support\Ai\Metrics;
use App\Support\Ai\PersonalData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analisis umpan balik survei dengan Claude.
 *
 * 1. Klasifikasi per komentar (sentimen, kategori, tema, perlu tindakan) lewat Message Batches + structured output.
 *    Komentar disaring dulu: email/telepon/NIK dan nama peserta/trainer kelas itu diganti penanda. Hasilnya insight
 *    internal (hanya staf), dipakai laporan admin dan diukur terhadap label manual.
 * 2. Ringkasan per kelas (kekuatan, perbaikan, usulan tindakan) dari angka agregat + komentar tersaring. Ringkasan
 *    berstatus draft dan baru tampil ke trainer setelah disetujui admin.
 * Tidak ada yang ditulis ke tabel resmi; survei dan nilai tidak diubah.
 */
class AiFeedbackService
{
    public const USE_CASE = 'feedback_analysis';

    public const SENTIMENTS = ['positif' => 'Positif', 'netral' => 'Netral', 'negatif' => 'Negatif'];

    public const CATEGORIES = [
        'materi' => 'Materi',
        'trainer' => 'Trainer',
        'praktik' => 'Praktik',
        'fasilitas' => 'Fasilitas & peralatan',
        'platform' => 'Platform online',
        'jadwal' => 'Jadwal & durasi',
        'administrasi' => 'Administrasi & layanan',
        'lainnya' => 'Lainnya',
    ];

    /** Minimal komentar terklasifikasi sebelum ringkasan kelas dibuat (ringkasan dari sedikit komentar mudah menunjuk orang). */
    public const SUMMARY_MIN_COMMENTS = 3;

    public function __construct(
        private readonly AiBatchRunner $batches,
        private readonly AiModelRunner $runner,
        private readonly AiInsightService $insights,
        private readonly AiGovernance $governance,
    ) {}

    /** @return array<string, mixed> */
    public static function classificationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sentiment' => ['type' => 'string', 'enum' => array_keys(self::SENTIMENTS)],
                'category' => ['type' => 'string', 'enum' => array_keys(self::CATEGORIES)],
                'theme' => ['type' => 'string', 'description' => 'Tema singkat 2-5 kata dalam Bahasa Indonesia'],
                'actionable' => ['type' => 'boolean', 'description' => 'true bila komentar berisi masukan yang bisa ditindaklanjuti'],
            ],
            'required' => ['sentiment', 'category', 'theme', 'actionable'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function summarySchema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string'], 'strengths' => $list, 'improvements' => $list, 'actions' => $list],
            'required' => ['summary', 'strengths', 'improvements', 'actions'],
            'additionalProperties' => false,
        ];
    }

    /** @return list<array{type:string,text:string,cache?:bool}> */
    private function classifySystem(): array
    {
        $cats = collect(self::CATEGORIES)->map(fn ($label, $key) => "- {$key}: {$label}")->implode("\n");

        return [['type' => 'text', 'cache' => true, 'text' => <<<TXT
        Anda mengklasifikasikan satu komentar survei evaluasi pelatihan di lembaga pelatihan teknologi industri.
        Tentukan:
        - sentiment: positif, netral, atau negatif terhadap pelatihan.
        - category: satu kategori yang paling tepat dari daftar berikut.
        {$cats}
        - theme: tema singkat 2-5 kata (contoh: "porsi praktik kurang", "penjelasan trainer jelas").
        - actionable: true bila komentar berisi masukan konkret yang bisa ditindaklanjuti.
        Penanda seperti [nama], [email], atau [telepon] adalah data yang sudah disamarkan; abaikan.
        Jawab hanya dengan JSON sesuai skema.
        TXT]];
    }

    // ------------------------------------------------------------------ klasifikasi (batch)

    /**
     * Satu putaran terjadwal: proses batch yang selesai, lalu kirim komentar yang belum diklasifikasikan.
     *
     * @return array{status: string, reason: ?string, ended: int, classified: int, submitted: int}
     */
    public function analyze(int $limit = 200): array
    {
        $classified = 0;
        $poll = $this->batches->poll(self::USE_CASE, function (array $ref, AiResult $r) use (&$classified): void {
            if ($this->recordClassification($ref, $r)) {
                $classified++;
            }
        });
        $status = $this->governance->status(self::USE_CASE);
        // Fitur belum dinyalakan: berhenti. Jeda / batas harian: tetap lanjut agar runner mencatat kejadiannya.
        if (in_array($status['reason'], ['global_off', 'flag_off', 'no_api_key'], true)) {
            return ['status' => 'unavailable', 'reason' => $status['reason_label'], 'ended' => $poll['ended'], 'classified' => $classified, 'submitted' => 0];
        }
        $items = $this->pending($limit)->map(fn (array $c) => [
            'custom_id' => 'c-'.$c['response']->id.'-'.$c['key'],
            'ref' => ['response_id' => $c['response']->id, 'question_key' => $c['key'], 'text_sha' => hash('sha256', $c['text'])],
            'messages' => [['role' => 'user', 'content' => 'Komentar: '.$this->scrub($c['text'], $c['response'])]],
        ])->values()->all();
        $batch = $items ? $this->batches->submit(self::USE_CASE, $items, $this->classifySystem(), self::classificationSchema()) : null;

        return ['status' => 'ok', 'reason' => null, 'ended' => $poll['ended'], 'classified' => $classified, 'submitted' => $batch?->request_count ?? 0];
    }

    /**
     * Komentar terisi yang belum diklasifikasikan dan belum pernah dikirim dalam batch mana pun.
     *
     * @return Collection<int, array{response: SurveyResponse, key: string, text: string}>
     */
    public function pending(int $limit = 200): Collection
    {
        $done = $this->classifications()->keys()->flip();
        $queued = AiBatch::query()->where('use_case', self::USE_CASE)->pluck('items')
            ->flatMap(fn ($items) => collect((array) $items)->map(fn ($r) => $r['response_id'].':'.$r['question_key']))->flip();
        $out = collect();
        SurveyResponse::query()->where('status', 'submitted')->with(['template', 'enrollment.member.user:id,name', 'schedule.trainers:id,name'])
            ->orderBy('id')->each(function (SurveyResponse $r) use ($done, $queued, &$out, $limit) {
                foreach ($r->comments() as $key => $text) {
                    $k = $r->id.':'.$key;
                    if (! $done->has($k) && ! $queued->has($k) && $out->count() < $limit) {
                        $out->push(['response' => $r, 'key' => $key, 'text' => $text]);
                    }
                }

                return $out->count() < $limit;
            });

        return $out;
    }

    /** Saring data pribadi: email/telepon/NIK + nama peserta dan trainer kelas itu diganti "[nama]". */
    public function scrub(string $text, SurveyResponse $r): string
    {
        $text = PersonalData::scrub($text);
        $names = collect([$r->enrollment?->participant_name, $r->enrollment?->member?->user?->name])
            ->merge($r->schedule?->trainers?->pluck('name') ?? [])->filter();
        $tokens = $names->flatMap(fn ($n) => [$n, ...preg_split('/\s+/', (string) $n)])
            ->map(fn ($t) => trim((string) $t, ' .,'))->filter(fn ($t) => mb_strlen($t) >= 4)->unique()->sortByDesc(fn ($t) => mb_strlen($t));
        foreach ($tokens as $t) {
            $text = (string) preg_replace('/(?<![\p{L}])'.preg_quote($t, '/').'(?![\p{L}])/iu', '[nama]', $text);
        }

        return $text;
    }

    /** @param  array{response_id: int, question_key: string, text_sha: string}  $ref */
    private function recordClassification(array $ref, AiResult $r): bool
    {
        $response = SurveyResponse::query()->find($ref['response_id']);
        if (! $r->ok() || ! $response) {
            return false;
        }
        $d = $r->data ?? [];
        $this->insights->record(
            self::USE_CASE,
            [
                'kind' => 'classification',
                'question_key' => $ref['question_key'],
                'sentiment' => array_key_exists($d['sentiment'] ?? '', self::SENTIMENTS) ? $d['sentiment'] : 'netral',
                'category' => array_key_exists($d['category'] ?? '', self::CATEGORIES) ? $d['category'] : 'lainnya',
                'theme' => mb_substr((string) ($d['theme'] ?? ''), 0, 120),
                'actionable' => (bool) ($d['actionable'] ?? false),
                'summary' => mb_substr((string) ($d['theme'] ?? ''), 0, 120),
            ],
            (new DataReference)->add($response, ['question_key' => $ref['question_key'], 'text_sha' => $ref['text_sha']]),
            subject: $response,
            modelVersion: $r->model,
            usage: $r->usage,
            internal: true,
        );

        return true;
    }

    /**
     * Klasifikasi AI terbaru per komentar ("responseId:key" → output).
     *
     * @param  iterable<int>|null  $responseIds
     * @return Collection<string, array<string, mixed>>
     */
    public function classifications(?iterable $responseIds = null): Collection
    {
        return AiInsight::query()->where('use_case', self::USE_CASE)->where('subject_type', (new SurveyResponse)->getMorphClass())
            ->where('status', '!=', AiInsight::STATUS_REJECTED)
            ->when($responseIds !== null, fn ($q) => $q->whereIn('subject_id', collect($responseIds)))
            ->orderBy('id')->get(['id', 'subject_id', 'output'])
            ->filter(fn (AiInsight $i) => ($i->output['kind'] ?? null) === 'classification')
            ->mapWithKeys(fn (AiInsight $i) => [$i->subject_id.':'.$i->output['question_key'] => $i->output + ['insight_id' => $i->id]]);
    }

    // ------------------------------------------------------------------ ringkasan per kelas

    /** Buat ringkasan untuk kelas yang punya cukup komentar terklasifikasi dan belum punya ringkasan terbaru. */
    public function summarizeDue(): int
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return 0;
        }
        $n = 0;
        $ids = SurveyResponse::query()->where('status', 'submitted')->distinct()->pluck('schedule_id');
        foreach (TrainingSchedule::query()->whereIn('id', $ids)->with('package:id,title')->get() as $s) {
            if ($this->summarize($s) !== null) {
                $n++;
            }
        }

        return $n;
    }

    public function summarize(TrainingSchedule $s): ?AiInsight
    {
        $responses = SurveyResponse::query()->where('schedule_id', $s->id)->where('status', 'submitted')
            ->with(['template', 'enrollment.member.user:id,name', 'schedule.trainers:id,name'])->get();
        $cls = $this->classifications($responses->pluck('id'));
        if ($cls->count() < self::SUMMARY_MIN_COMMENTS) {
            return null;
        }
        $latest = $this->summaryFor($s, approvedOnly: false);
        if ($latest && $latest->created_at->gte(AiInsight::query()->whereIn('id', $cls->pluck('insight_id'))->max('created_at'))) {
            return null;
        }
        $report = app(SurveyService::class)->classReport($s);
        $lines = ['Kelas: '.($s->title ?? $s->package?->title).' ('.$s->code.')', 'Respons: '.$report['submitted'].' dari '.$report['invited'].' peserta diundang.', 'Rata-rata skor 1-5:'];
        foreach ($report['questions'] as $q) {
            $lines[] = '- '.$q['label'].': '.($q['average'] ?? '-');
        }
        $lines[] = 'Sebaran sentimen: '.$cls->countBy('sentiment')->map(fn ($n, $k) => "$k $n")->implode(', ');
        $lines[] = 'Sebaran kategori: '.$cls->countBy('category')->map(fn ($n, $k) => "$k $n")->implode(', ');
        $lines[] = 'Komentar (sudah disamarkan):';
        foreach ($responses as $r) {
            foreach ($r->comments() as $key => $text) {
                $c = $cls->get($r->id.':'.$key);
                if ($c && count($lines) < 80) {
                    $lines[] = '- ['.$c['sentiment'].', '.$c['category'].'] '.mb_substr($this->scrub($text, $r), 0, 400);
                }
            }
        }
        $result = $this->runner->run(self::USE_CASE, [['type' => 'text', 'cache' => true, 'text' => <<<'TXT'
        Anda menyusun ringkasan umpan balik survei satu kelas pelatihan untuk tim pelatihan dan trainer.
        Tulis ringkasan 2-4 kalimat, lalu daftar kekuatan, hal yang perlu diperbaiki, dan usulan tindakan konkret (masing-masing 1-4 butir pendek).
        Dasarkan hanya pada angka dan komentar yang diberikan. Jangan menyebut atau menebak identitas peserta. Jangan menilai pribadi trainer di luar isi komentar.
        Jawab hanya dengan JSON sesuai skema.
        TXT]], [['role' => 'user', 'content' => implode("\n", $lines)]], self::summarySchema());
        if (! $result->ok()) {
            return null;
        }
        $d = $result->data ?? [];
        $insight = $this->insights->record(
            self::USE_CASE,
            [
                'kind' => 'class_summary',
                'summary' => (string) ($d['summary'] ?? ''),
                'strengths' => array_values(array_map('strval', (array) ($d['strengths'] ?? []))),
                'improvements' => array_values(array_map('strval', (array) ($d['improvements'] ?? []))),
                'actions' => array_values(array_map('strval', (array) ($d['actions'] ?? []))),
                'responses' => $report['submitted'],
                'comments' => $cls->count(),
            ],
            (new DataReference)->add($s, ['responses' => $responses->pluck('id')->all(), 'classifications' => $cls->pluck('insight_id')->values()->all()]),
            subject: $s,
            modelVersion: $result->model,
            usage: $result->usage,
        );
        // Draf lama diganti draf baru; ringkasan yang sudah disetujui tetap tampil ke trainer sampai yang baru disetujui.
        $this->insights->supersede(self::USE_CASE, $s, $insight->id, [AiInsight::STATUS_DRAFT]);

        return $insight;
    }

    /** Ringkasan kelas terbaru (untuk trainer: hanya yang sudah disetujui admin). */
    public function summaryFor(TrainingSchedule $s, bool $approvedOnly = true): ?AiInsight
    {
        return AiInsight::query()->where('use_case', self::USE_CASE)->where('subject_type', $s->getMorphClass())->where('subject_id', $s->id)
            ->when($approvedOnly, fn ($q) => $q->visible(), fn ($q) => $q->whereIn('status', [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED]))
            ->latest('id')->first();
    }

    // ------------------------------------------------------------------ evaluasi

    /** Baseline kata kunci (tanpa AI) untuk pembanding akurasi. @return array{sentiment: string, category: string} */
    public static function keywordBaseline(string $text): array
    {
        $t = mb_strtolower($text);
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($t, $w));
        $neg = $has(['kurang', 'tidak ', 'lambat', 'buruk', 'susah', 'sulit', 'kecewa', 'rusak', 'terlalu', 'bingung', 'mati', 'error']);
        $pos = $has(['bagus', 'baik', 'jelas', 'puas', 'mantap', 'bermanfaat', 'membantu', 'senang', 'menarik', 'lengkap']);
        $category = match (true) {
            $has(['trainer', 'instruktur', 'pengajar', 'mentor']) => 'trainer',
            $has(['praktik', 'praktek', 'hands-on', 'lab']) => 'praktik',
            $has(['zoom', 'online', 'platform', 'lms', 'video', 'koneksi', 'sinyal']) => 'platform',
            $has(['ruang', 'ac', 'alat', 'peralatan', 'komputer', 'proyektor', 'fasilitas', 'konsumsi']) => 'fasilitas',
            $has(['jadwal', 'waktu', 'durasi', 'jam', 'hari']) => 'jadwal',
            $has(['materi', 'modul', 'slide', 'topik', 'contoh']) => 'materi',
            $has(['admin', 'pendaftaran', 'pembayaran', 'sertifikat', 'layanan']) => 'administrasi',
            default => 'lainnya',
        };

        return ['sentiment' => $neg && ! $pos ? 'negatif' : ($pos && ! $neg ? 'positif' : 'netral'), 'category' => $category];
    }

    /**
     * Akurasi & macro-F1 klasifikasi AI terhadap label manual admin, dibanding baseline kata kunci.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $labels = FeedbackLabel::query()->with('response.template')->get();
        $cls = $this->classifications($labels->pluck('response_id')->unique());
        $pairs = $labels->map(function (FeedbackLabel $l) use ($cls) {
            $ai = $cls->get($l->response_id.':'.$l->question_key);
            $text = (string) ($l->response?->answers[$l->question_key] ?? '');

            return $ai ? ['label' => $l, 'ai' => $ai, 'base' => self::keywordBaseline($text)] : null;
        })->filter()->values();
        $m = fn (string $field, string $who) => Metrics::multiclass(
            $pairs->map(fn ($p) => $who === 'ai' ? $p['ai'][$field] : $p['base'][$field])->all(),
            $pairs->map(fn ($p) => $p['label']->{$field})->all(),
        );
        $actionable = Metrics::binary($pairs->map(fn ($p) => (bool) $p['ai']['actionable'])->all(), $pairs->map(fn ($p) => (bool) $p['label']->actionable)->all());
        $sentiment = $m('sentiment', 'ai');
        $baseSentiment = $m('sentiment', 'base');

        return [
            'labeled' => $labels->count(),
            'compared' => $pairs->count(),
            'unlabeled_classified' => max(0, $this->classifications()->count() - $pairs->count()),
            'sentiment' => $sentiment,
            'category' => $m('category', 'ai'),
            'baseline_sentiment' => $baseSentiment,
            'baseline_category' => $m('category', 'base'),
            'actionable' => $actionable,
            'beats_baseline' => $pairs->isEmpty() || $sentiment['macro_f1'] === null ? null : $sentiment['macro_f1'] >= ($baseSentiment['macro_f1'] ?? 0),
        ];
    }

    /** Jumlah komentar survei terisi (kesiapan data). */
    public static function commentCount(): int
    {
        return (int) SurveyResponse::query()->where('status', 'submitted')->with('template')->get()->sum(fn (SurveyResponse $r) => count($r->comments()));
    }

    /** Sebaran sentimen per kelas untuk laporan admin. @return array<string, int> */
    public function sentimentFor(TrainingSchedule $s): array
    {
        $ids = DB::table('survey_responses')->where('schedule_id', $s->id)->pluck('id');

        return $this->classifications($ids)->countBy('sentiment')->all();
    }
}
