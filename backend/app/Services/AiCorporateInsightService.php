<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Support\Ai\DataReference;
use App\Support\Ai\PersonalData;
use Illuminate\Support\Collection;

/**
 * Insight perusahaan (use case corporate_insight): ringkasan gap kompetensi + usulan tindakan per organisasi.
 *
 * - Masukan hanya ANGKA GABUNGAN dari matriks kompetensi (CompetencyGapService, bukti dari training yang disponsori
 *   organisasi itu saja) dan hasil training bersponsor. Tidak ada nama, ID karyawan, atau baris per orang. Kelompok
 *   (posisi x kompetensi, unit) dengan kurang dari MIN_GROUP karyawan tidak dirinci agar tidak bisa dikenali.
 * - Claude menulis narasi berstruktur (structured output). Program yang disarankan harus berasal dari daftar program
 *   yang diberikan; judul lain dibuang sehingga setiap saran bisa ditelusuri ke katalog.
 * - Selalu mulai sebagai DRAFT dan baru tampil di portal perusahaan setelah disetujui admin VMES (izin ai.review).
 * - Tampil hanya untuk pengguna perusahaan tingkat organisasi (tanpa batas unit) yang boleh melihat progres atau
 *   mengelola kompetensi, karena isinya merangkum seluruh unit.
 * - Metrik: acceptance rate (disetujui vs ditolak admin), konversi menjadi permintaan training dalam 30 hari setelah
 *   tampil, dan umpan balik pengguna perusahaan.
 */
class AiCorporateInsightService
{
    public const USE_CASE = 'corporate_insight';

    /** Kelompok lebih kecil dari ini tidak dirinci (anonimitas). */
    public const MIN_GROUP = 3;

    /** Minimal karyawan bertarget kompetensi agar insight dibuat. */
    public const MIN_EMPLOYEES = 3;

    public const CONVERSION_DAYS = 30;

    public const TTL_DAYS = 60;

    /** Mengubah teks ini = menaikkan prompt_version corporate_insight di config/ai.php. */
    public const SYSTEM_PROMPT = <<<'TXT'
    Anda membantu tim pelatihan PPTI VMES menyusun insight kompetensi untuk satu perusahaan klien. Pembacanya HR atau L&D perusahaan tersebut.
    Data yang diberikan hanya angka gabungan: target kompetensi per posisi, cakupan karyawan yang sudah memenuhi target, rata-rata kekurangan poin, dan hasil training yang disponsori perusahaan. Kelompok kecil sengaja tidak dirinci.

    Tulis:
    1. summary: 2-4 kalimat tentang kondisi kompetensi organisasi.
    2. key_gaps: paling banyak 4 gap terpenting, masing-masing berupa nama kompetensi (persis seperti di data) dan temuan singkat yang menyebut angkanya.
    3. actions: paling banyak 4 usulan tindakan konkret dengan alasan dan prioritas (tinggi, sedang, rendah). Bila menyarankan program, isi program dengan judul persis dari daftar program yang tersedia; bila tidak ada program yang cocok, isi dengan string kosong.
    4. limitations: satu kalimat tentang keterbatasan data, misalnya banyak karyawan belum punya bukti penilaian.

    Dasarkan semua pernyataan hanya pada data yang diberikan. Jangan menebak identitas karyawan, jangan menyebut individu, dan jangan menjanjikan hasil. Jawab hanya dengan JSON sesuai skema.
    TXT;

    public function __construct(
        private readonly CompetencyGapService $gaps,
        private readonly AiModelRunner $runner,
        private readonly AiGovernance $governance,
        private readonly AiInsightService $insights,
    ) {}

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => $str,
                'key_gaps' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['competency' => $str, 'finding' => $str], 'required' => ['competency', 'finding']]],
                'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['action' => $str, 'reason' => $str, 'priority' => ['type' => 'string', 'enum' => ['tinggi', 'sedang', 'rendah']], 'program' => $str],
                    'required' => ['action', 'reason', 'priority', 'program']]],
                'limitations' => $str,
            ],
            'required' => ['summary', 'key_gaps', 'actions', 'limitations'],
        ];
    }

    /** @return array{available: bool, label: string, reason: ?string} */
    public function status(): array
    {
        $s = $this->governance->status(self::USE_CASE);

        return ['available' => $s['available'], 'label' => $s['available'] ? 'Aktif' : 'Belum tersedia', 'reason' => $s['reason_label']];
    }

    // ------------------------------------------------------------------ data gabungan

    /**
     * Angka gabungan organisasi yang menjadi satu-satunya masukan model.
     *
     * @return array<string, mixed>
     */
    public function aggregate(Organization $org): array
    {
        $matrix = $this->gaps->forOrganization($org);
        $rows = collect($matrix['rows']);
        $min = self::MIN_GROUP;
        $stat = fn (Collection $g) => [
            'employees' => $g->pluck('member_id')->unique()->count(),
            'met' => $g->where('met', true)->count(),
            'rows' => $g->count(),
            'coverage' => $g->count() > 0 ? round($g->where('met', true)->count() / $g->count() * 100, 1) : null,
            'no_evidence' => $g->whereNull('actual')->count(),
            'avg_gap' => round((float) $g->avg(fn ($r) => max(0, $r['gap'])), 1),
        ];

        $competencies = $rows->groupBy('competency_id')->map(fn (Collection $g) => ['competency' => $g->first()['competency'], 'code' => $g->first()['competency_code']] + $stat($g))
            ->filter(fn ($c) => $c['employees'] >= $min)->sortByDesc('avg_gap')->values();
        // Posisi dicocokkan seperti matriks (huruf besar/kecil & spasi diabaikan); label = penulisan yang paling sering.
        $positions = $rows->groupBy(fn ($r) => CompetencyTarget::key($r['position']).'|'.$r['competency_id'])
            ->map(fn (Collection $g) => ['position' => (string) $g->countBy('position')->sortDesc()->keys()->first(), 'competency' => $g->first()['competency'], 'required' => $g->first()['required']] + $stat($g))
            ->filter(fn ($p) => $p['employees'] >= $min)->sortByDesc('avg_gap')->values();
        $units = $rows->whereNotNull('unit')->groupBy('unit')
            ->map(function (Collection $g, $unit) use ($stat) {
                $worst = $g->groupBy('competency')->map(fn ($c) => (float) $c->avg(fn ($r) => max(0, $r['gap'])))->sortDesc();

                return ['unit' => (string) $unit, 'top_gap' => $worst->keys()->first()] + $stat($g);
            })
            ->filter(fn ($u) => $u['employees'] >= $min)->sortBy('coverage')->values();
        $hidden = $rows->pluck('competency_id')->unique()->count() - $competencies->count();

        // Program terbit yang membangun kompetensi bergap (dari rekomendasi matriks; tanpa data per orang).
        $programs = $rows->where('gap', '>', 0)->whereNotNull('recommendation')->groupBy('competency')
            ->map(fn (Collection $g) => $g->pluck('recommendation')->unique('slug')->take(3)->map(fn ($p) => ['slug' => $p['slug'], 'title' => $p['title'], 'level' => $p['level']])->values()->all())
            ->filter(fn ($p, $comp) => $competencies->contains('competency', $comp));

        $since = now()->subMonths(12)->startOfDay();
        $outcomes = Enrollment::query()->where('type', Enrollment::TYPE_CORPORATE)->where('sponsor_organization_id', $org->id)
            ->where('enrolled_at', '>=', $since)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return [
            'employees' => $matrix['employees'],
            'employees_with_targets' => $matrix['employees_with_targets'],
            'positions_with_targets' => count($matrix['positions']),
            'competencies' => $competencies->all(),
            'positions' => $positions->take(12)->all(),
            'units' => $units->take(10)->all(),
            'hidden_small_groups' => max(0, $hidden),
            'programs' => $programs->all(),
            'outcomes_12m' => [
                'enrolled' => (int) $outcomes->sum(),
                'completed' => (int) ($outcomes['completed'] ?? 0),
                'failed' => (int) ($outcomes['failed'] ?? 0),
                'in_progress' => (int) (($outcomes['enrolled'] ?? 0) + ($outcomes['attending'] ?? 0)),
                'dropped' => (int) ($outcomes['dropped'] ?? 0),
            ],
        ];
    }

    /** Teks masukan model dari data gabungan. Tidak memuat nama organisasi maupun karyawan. @param array<string, mixed> $a */
    public static function prompt(array $a): string
    {
        $l = ['Data gabungan satu organisasi klien (tanpa identitas karyawan). Kelompok dengan kurang dari '.self::MIN_GROUP.' karyawan tidak dirinci.',
            "Karyawan aktif: {$a['employees']}. Karyawan pada posisi yang punya target kompetensi: {$a['employees_with_targets']}. Posisi bertarget: {$a['positions_with_targets']}.",
            '', 'Per kompetensi (target posisi dibanding bukti dari training yang disponsori perusahaan):'];
        foreach ($a['competencies'] as $c) {
            $l[] = "- {$c['competency']}: {$c['employees']} karyawan, memenuhi target {$c['met']} dari {$c['rows']} ({$c['coverage']}%), belum ada bukti {$c['no_evidence']}, rata-rata kekurangan {$c['avg_gap']} poin.";
        }
        if ($a['hidden_small_groups'] > 0) {
            $l[] = "- {$a['hidden_small_groups']} kompetensi lain tidak dirinci karena kelompoknya kecil.";
        }
        $l[] = '';
        $l[] = 'Per posisi dan kompetensi (target level 0-100):';
        foreach ($a['positions'] as $p) {
            $l[] = "- {$p['position']} / {$p['competency']}: target {$p['required']}, {$p['employees']} karyawan, memenuhi {$p['met']}, rata-rata kekurangan {$p['avg_gap']} poin.";
        }
        if ($a['units'] !== []) {
            $l[] = '';
            $l[] = 'Per unit:';
            foreach ($a['units'] as $u) {
                $l[] = "- {$u['unit']}: {$u['employees']} karyawan bertarget, cakupan {$u['coverage']}%, gap terbesar {$u['top_gap']}.";
            }
        }
        $o = $a['outcomes_12m'];
        $l[] = '';
        $l[] = "Training yang disponsori perusahaan 12 bulan terakhir: {$o['enrolled']} peserta, lulus {$o['completed']}, tidak lulus {$o['failed']}, berjalan {$o['in_progress']}, mengundurkan diri {$o['dropped']}.";
        $l[] = '';
        $l[] = 'Program yang tersedia untuk kompetensi bergap (pakai judul persis bila menyarankan program):';
        if ($a['programs'] === []) {
            $l[] = '- Tidak ada program yang cocok di katalog.';
        }
        foreach ($a['programs'] as $comp => $list) {
            $l[] = "- {$comp}: ".implode('; ', array_map(fn ($p) => "\"{$p['title']}\" (level {$p['level']})", $list));
        }

        return PersonalData::scrub(implode("\n", $l));
    }

    // ------------------------------------------------------------------ pembuatan

    /** Buat insight untuk organisasi bertarget yang datanya berubah sejak insight terakhir. */
    public function generateDue(): int
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return 0;
        }
        $n = 0;
        $ids = CompetencyTarget::query()->distinct()->pluck('organization_id');
        foreach (Organization::query()->whereIn('id', $ids)->active()->orderBy('id')->get() as $org) {
            if ($this->generate($org) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** Draf insight baru, atau null (fitur mati, data kurang, tidak berubah, atau model gagal: tetap laporan tanpa narasi). */
    public function generate(Organization $org, bool $force = false): ?AiInsight
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return null;
        }
        $a = $this->aggregate($org);
        if ($a['employees_with_targets'] < self::MIN_EMPLOYEES || $a['competencies'] === []) {
            return null;
        }
        $hash = DataReference::hash($a);
        $latest = $this->latest($org, approvedOnly: false);
        if (! $force && $latest && ($latest->output['input_hash'] ?? null) === $hash) {
            return null;
        }

        $result = $this->runner->run(self::USE_CASE, [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache' => true]],
            [['role' => 'user', 'content' => self::prompt($a)]], self::schema());
        if (! $result->ok()) {
            return null;
        }
        $d = $result->data ?? [];
        $programs = collect($a['programs'])->flatten(1)->keyBy(fn ($p) => mb_strtolower(trim($p['title'])));
        $known = collect($a['competencies'])->pluck('competency')->map(fn ($c) => mb_strtolower($c));

        $insight = $this->insights->record(self::USE_CASE, [
            'kind' => 'organization',
            'summary' => (string) ($d['summary'] ?? ''),
            'key_gaps' => collect((array) ($d['key_gaps'] ?? []))->take(4)
                ->filter(fn ($g) => $known->contains(mb_strtolower(trim((string) ($g['competency'] ?? '')))))
                ->map(fn ($g) => ['competency' => (string) $g['competency'], 'finding' => (string) ($g['finding'] ?? '')])->values()->all(),
            'actions' => collect((array) ($d['actions'] ?? []))->take(4)->map(function ($x) use ($programs) {
                $p = $programs->get(mb_strtolower(trim((string) ($x['program'] ?? ''))));

                return [
                    'action' => (string) ($x['action'] ?? ''),
                    'reason' => (string) ($x['reason'] ?? ''),
                    'priority' => in_array($x['priority'] ?? null, ['tinggi', 'sedang', 'rendah'], true) ? $x['priority'] : 'sedang',
                    // Hanya program dari katalog yang diberikan; judul karangan model dibuang.
                    'program' => $p ? ['slug' => $p['slug'], 'title' => $p['title']] : null,
                ];
            })->filter(fn ($x) => $x['action'] !== '')->values()->all(),
            'limitations' => (string) ($d['limitations'] ?? ''),
            'figures' => [
                'employees_with_targets' => $a['employees_with_targets'],
                'competencies' => array_map(fn ($c) => ['competency' => $c['competency'], 'coverage' => $c['coverage'], 'avg_gap' => $c['avg_gap'], 'employees' => $c['employees']], $a['competencies']),
            ],
            'input_hash' => $hash,
        ], (new DataReference)->add($org, $a), subject: $org, organizationId: $org->id, modelVersion: $result->model,
            usage: $result->usage, expiresAt: now()->addDays(self::TTL_DAYS));

        // Draf lama diganti draf baru; insight yang sudah disetujui tetap tampil sampai yang baru disetujui.
        $this->insights->supersede(self::USE_CASE, $org, $insight->id, [AiInsight::STATUS_DRAFT]);

        return $insight;
    }

    /** Insight terbaru organisasi (untuk perusahaan: hanya yang sudah disetujui dan masih berlaku). */
    public function latest(Organization $org, bool $approvedOnly = true): ?AiInsight
    {
        return AiInsight::query()->where('use_case', self::USE_CASE)->where('subject_type', $org->getMorphClass())->where('subject_id', $org->id)
            ->when($approvedOnly, fn ($q) => $q->visible(), fn ($q) => $q->whereIn('status', [AiInsight::STATUS_DRAFT, AiInsight::STATUS_APPROVED]))
            ->latest('id')->first();
    }

    // ------------------------------------------------------------------ portal perusahaan

    /** Pengguna perusahaan tingkat organisasi yang boleh melihat insight (isinya merangkum semua unit). */
    public function canView(CorporateUser $cu): bool
    {
        return $cu->isActive() && $cu->unit_id === null && ($cu->allows('view_progress') || $cu->allows('manage_competency'));
    }

    /** @return array<string, mixed>|null null bila fitur mati, pengguna tidak berhak, atau belum ada insight disetujui */
    public function forCorporate(CorporateUser $cu, User $viewer): ?array
    {
        if (! $this->governance->available(self::USE_CASE) || ! $this->canView($cu)) {
            return null;
        }
        $insight = $this->latest($cu->organization);
        if (! $insight) {
            return null;
        }
        $o = (array) $insight->output;

        return [
            'insightId' => $insight->id,
            'summary' => (string) ($o['summary'] ?? ''),
            'keyGaps' => array_values((array) ($o['key_gaps'] ?? [])),
            'actions' => array_values((array) ($o['actions'] ?? [])),
            'limitations' => (string) ($o['limitations'] ?? ''),
            'approvedAt' => $insight->reviewed_at?->toIso8601String(),
            'myFeedback' => AiInsightFeedback::query()->where('ai_insight_id', $insight->id)->where('user_id', $viewer->id)->value('helpful'),
        ];
    }

    // ------------------------------------------------------------------ evaluasi

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $all = AiInsight::query()->where('use_case', self::USE_CASE)->get(['id', 'organization_id', 'status', 'reviewed_at', 'rejection_reason', 'output']);
        $approved = $all->filter(fn (AiInsight $i) => $i->reviewed_at !== null && in_array($i->status, [AiInsight::STATUS_APPROVED, AiInsight::STATUS_EXPIRED], true) && $i->rejection_reason === null);
        $rejected = $all->where('status', AiInsight::STATUS_REJECTED);
        $decided = $approved->count() + $rejected->count();

        // Konversi: permintaan training organisasi itu dalam 30 hari setelah insight disetujui (yang sudah lewat 30 hari saja).
        $mature = $approved->filter(fn (AiInsight $i) => $i->reviewed_at->lte(now()->subDays(self::CONVERSION_DAYS)));
        $converted = 0;
        $convertedSuggested = 0;
        foreach ($mature as $i) {
            $reqs = TrainingRequest::query()->where('organization_id', $i->organization_id)
                ->whereBetween('created_at', [$i->reviewed_at, $i->reviewed_at->copy()->addDays(self::CONVERSION_DAYS)])->with('package:id,slug')->get();
            $suggested = collect((array) ($i->output['actions'] ?? []))->pluck('program.slug')->filter()->all();
            $converted += $reqs->isNotEmpty() ? 1 : 0;
            $convertedSuggested += $reqs->contains(fn ($r) => in_array($r->package?->slug, $suggested, true)) ? 1 : 0;
        }

        // Pembanding: organisasi bertarget yang belum pernah menerima insight disetujui, permintaan training dalam 30 hari terakhir.
        $withInsight = $approved->pluck('organization_id')->unique();
        $baselineOrgs = CompetencyTarget::query()->distinct()->pluck('organization_id')->diff($withInsight);
        $baselineConverted = $baselineOrgs->isEmpty() ? 0 : TrainingRequest::query()->whereIn('organization_id', $baselineOrgs)
            ->where('created_at', '>=', now()->subDays(self::CONVERSION_DAYS))->distinct()->count('organization_id');

        $feedback = AiInsightFeedback::query()->whereIn('ai_insight_id', $approved->pluck('id'))->get(['helpful']);

        return [
            'insights' => $all->count(),
            'approved' => $approved->count(),
            'rejected' => $rejected->count(),
            'pending' => $all->where('status', AiInsight::STATUS_DRAFT)->count(),
            'acceptance_rate' => $decided > 0 ? round($approved->count() / $decided * 100, 1) : null,
            'mature' => $mature->count(),
            'conversion_rate' => $mature->count() > 0 ? round($converted / $mature->count() * 100, 1) : null,
            'suggested_program_conversion_rate' => $mature->count() > 0 ? round($convertedSuggested / $mature->count() * 100, 1) : null,
            'feedback' => $feedback->count(),
            'helpful_rate' => $feedback->count() > 0 ? round($feedback->where('helpful', true)->count() / $feedback->count() * 100, 1) : null,
            'baseline_organizations' => $baselineOrgs->count(),
            'baseline_conversion_rate' => $baselineOrgs->count() > 0 ? round($baselineConverted / $baselineOrgs->count() * 100, 1) : null,
        ];
    }
}
