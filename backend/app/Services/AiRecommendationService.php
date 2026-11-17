<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiRecommendationEvent;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Member;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Support\Ai\DataReference;
use App\Support\Ai\Metrics;
use App\Support\Ai\Recommendation\Recommender;
use App\Support\IdDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rekomendasi training (statistik/aturan, tanpa LLM).
 *
 * - Member/alumni: kandidat dari riwayat (prasyarat, bidang), gap kompetensi posisinya, dan pola peserta lain.
 * - Perusahaan: per posisi dari matriks kompetensi (hanya bukti training yang disponsori perusahaan, angka agregat tanpa nama).
 * Hasil dicatat sebagai insight `recommendation` (langsung tampil, risiko rendah) sekali per hari per penerima; setiap
 * tampilan dan klik dicatat untuk CTR. Konversi dihitung saat evaluasi dari pendaftaran / permintaan training yang sudah ada.
 */
class AiRecommendationService
{
    public const USE_CASE = 'recommendation';

    /** Hari setelah impresi yang masih dihitung sebagai konversi. */
    public const CONVERSION_DAYS = 30;

    public const REPEAT_DAYS = 60;

    private ?array $catalogCache = null;

    public function __construct(
        private readonly Recommender $recommender,
        private readonly AiGovernance $governance,
        private readonly AiInsightService $insights,
        private readonly CompetencyGapService $gaps,
    ) {}

    /** @return array{available: bool, label: string, reason: ?string} */
    public function status(): array
    {
        $s = $this->governance->status(self::USE_CASE);

        return ['available' => $s['available'], 'label' => $s['available'] ? 'Aktif' : 'Belum tersedia', 'reason' => $s['reason_label']];
    }

    // ------------------------------------------------------------------ member

    /** @return array<string, mixed>|null null bila use case belum tersedia */
    public function forMember(Member $member, User $viewer): ?array
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return null;
        }
        $insight = $this->todays($member) ?? $this->recordMember($member);
        $this->impressions($insight, $viewer, 'member', $member->id, null);

        return $this->payload($insight, $viewer);
    }

    private function recordMember(Member $member): AiInsight
    {
        $profile = $this->profile($member);
        $items = $this->recommender->rank($profile, $this->catalog(), $this->coCounts(array_keys($profile['completed']) ?: $profile['taken'], $member->id));

        return $this->record($member, null, ['items' => $this->storeItems($items)], ['profile' => $profile]);
    }

    /**
     * Profil member: program lulus, program yang sudah diikuti/didaftarkan, bidang, dan gap kompetensi posisinya.
     *
     * @return array{completed: array<int, string>, taken: list<int>, categories: array<int, string>, gaps: array<int, array{gap: int, name: string, position: ?string}>}
     */
    public function profile(Member $member): array
    {
        $rows = DB::table('enrollments')->join('training_schedules', 'training_schedules.id', '=', 'enrollments.schedule_id')
            ->join('training_packages', 'training_packages.id', '=', 'training_schedules.package_id')
            ->where('enrollments.member_id', $member->id)->where('enrollments.status', '!=', 'dropped')
            ->orderBy('enrollments.id')
            ->get(['training_packages.id', 'training_packages.title', 'training_packages.category_id', 'enrollments.status']);
        $registered = DB::table('registrations')->where('member_id', $member->id)->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereNotNull('package_id')->pluck('package_id');

        return $this->profileFrom($rows, $registered, $this->memberGaps($member, $rows->where('status', 'completed')->pluck('id')));
    }

    /** @return array{completed: array<int, string>, taken: list<int>, categories: array<int, string>, gaps: array<int, array<string, mixed>>} */
    private function profileFrom(Collection $rows, Collection $registered, array $gaps = []): array
    {
        $completed = $rows->where('status', 'completed')->mapWithKeys(fn ($r) => [(int) $r->id => $r->title])->all();
        $categories = [];
        foreach ($rows as $r) {
            if ($r->category_id !== null && ! isset($categories[(int) $r->category_id])) {
                $categories[(int) $r->category_id] = $r->title;
            }
        }

        return [
            'completed' => $completed,
            'taken' => $rows->pluck('id')->merge($registered)->map(fn ($v) => (int) $v)->unique()->values()->all(),
            'categories' => $categories,
            'gaps' => $gaps,
        ];
    }

    /**
     * Gap kompetensi untuk posisi aktif member (target organisasinya). Level aktual dari program yang ia luluskan sendiri:
     * ini tampilan untuk dirinya sendiri, jadi seluruh riwayatnya boleh dipakai.
     *
     * @return array<int, array{gap: int, name: string, position: ?string}>
     */
    private function memberGaps(Member $member, Collection $completedPackageIds): array
    {
        $jobs = EmploymentRelationship::query()->where('member_id', $member->id)->active()->whereNotNull('position')->get(['organization_id', 'position']);
        if ($jobs->isEmpty()) {
            return [];
        }
        $levels = DB::table('package_competencies')->whereIn('package_id', $completedPackageIds)->get()
            ->groupBy('competency_id')->map(fn ($g) => (int) $g->max('level'));
        $gaps = [];
        foreach ($jobs as $job) {
            $targets = CompetencyTarget::query()->where('organization_id', $job->organization_id)->where('position_key', CompetencyTarget::key($job->position))
                ->whereHas('competency', fn ($q) => $q->active())->with('competency:id,name')->get();
            foreach ($targets as $t) {
                $gap = $t->required_level - (int) ($levels[$t->competency_id] ?? 0);
                if ($gap > 0 && $gap > ($gaps[$t->competency_id]['gap'] ?? 0)) {
                    $gaps[$t->competency_id] = ['gap' => $gap, 'name' => $t->competency->name, 'position' => $job->position];
                }
            }
        }

        return $gaps;
    }

    // ------------------------------------------------------------------ perusahaan

    /**
     * Rekomendasi per posisi untuk pengguna perusahaan (batas unit mengikuti pengguna). Hanya angka agregat.
     *
     * @return array<string, mixed>|null
     */
    public function forCorporate(CorporateUser $cu, User $viewer): ?array
    {
        if (! $this->governance->available(self::USE_CASE)) {
            return null;
        }
        $insight = $this->todays($cu) ?? $this->recordCorporate($cu);
        $this->impressions($insight, $viewer, 'corporate', null, $cu->organization_id);

        return $this->payload($insight, $viewer);
    }

    private function recordCorporate(CorporateUser $cu): AiInsight
    {
        $rows = collect($this->gaps->matrix($cu)['rows'])->where('gap', '>', 0);
        $catalog = collect($this->catalog())->keyBy('id');
        $groups = [];
        foreach ($rows->groupBy('position') as $position => $g) {
            $byComp = $g->groupBy('competency_id');
            $candidates = [];
            foreach ($catalog as $p) {
                $reasons = [];
                foreach ($byComp as $cid => $cg) {
                    if (! isset($p['competencies'][$cid])) {
                        continue;
                    }
                    $n = $cg->pluck('member_id')->unique()->count();
                    $avg = (int) round($cg->avg('gap'));
                    $reasons[] = ['source' => 'gap', 'points' => $n * 5 + min(Recommender::GAP_MAX, $avg),
                        'text' => "{$n} karyawan posisi {$position} di bawah target {$cg->first()['competency']}, rata-rata kurang {$avg} poin"];
                }
                if ($reasons !== []) {
                    usort($reasons, fn ($a, $b) => $b['points'] <=> $a['points']);
                    $candidates[] = ['package_id' => $p['id'], 'slug' => $p['slug'], 'title' => $p['title'], 'category' => $p['category'],
                        'score' => array_sum(array_column($reasons, 'points')), 'popularity' => $p['popularity'], 'reasons' => $reasons, 'next' => $p['next']];
                }
            }
            usort($candidates, fn ($a, $b) => [$b['score'], $b['popularity'], $a['title']] <=> [$a['score'], $a['popularity'], $b['title']]);
            if ($candidates !== []) {
                $groups[] = [
                    'position' => (string) $position,
                    'employees' => $g->pluck('member_id')->unique()->count(),
                    'items' => $this->storeItems(array_map(fn ($c, $i) => $c + ['rank' => $i + 1], $top = array_slice($candidates, 0, 2), array_keys($top))),
                ];
            }
        }
        usort($groups, fn ($a, $b) => [$b['employees'], $a['position']] <=> [$a['employees'], $b['position']]);
        $groups = array_slice($groups, 0, 8);

        return $this->record($cu, $cu->organization_id, ['groups' => $groups, 'items' => collect($groups)->flatMap(fn ($g) => $g['items'])->unique('package_id')->values()->all()],
            ['positions' => array_column($groups, 'position'), 'rows' => $rows->count()]);
    }

    // ------------------------------------------------------------------ pencatatan

    private function todays(Member|CorporateUser $subject): ?AiInsight
    {
        return AiInsight::query()->visible()->where('use_case', self::USE_CASE)
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where('created_at', '>=', today())->latest('id')->first();
    }

    /** @param  array<string, mixed>  $output */
    private function record(Member|CorporateUser $subject, ?int $organizationId, array $output, array $used): AiInsight
    {
        $insight = $this->insights->record(
            self::USE_CASE,
            $output + ['summary' => collect($output['items'])->pluck('title')->implode(', ') ?: 'Belum ada rekomendasi'],
            (new DataReference)->add($subject, $used),
            subject: $subject,
            organizationId: $organizationId,
            modelVersion: 'rules-2026-11-17',
            expiresAt: today()->endOfDay(),
        );
        $this->insights->supersede(self::USE_CASE, $subject, $insight->id);

        return $insight;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function storeItems(array $items): array
    {
        return array_map(fn ($i) => [
            'package_id' => $i['package_id'], 'slug' => $i['slug'], 'title' => $i['title'], 'category' => $i['category'],
            'score' => $i['score'], 'rank' => $i['rank'] ?? null,
            'reasons' => array_map(fn ($r) => ['source' => $r['source'], 'text' => $r['text'], 'points' => $r['points']], $i['reasons']),
            'next' => $i['next'],
        ], $items);
    }

    /** Impresi dicatat sekali per insight × program × pengguna (muat ulang halaman tidak menggandakan). */
    private function impressions(AiInsight $insight, User $viewer, string $context, ?int $memberId, ?int $organizationId): void
    {
        $seen = AiRecommendationEvent::query()->where('ai_insight_id', $insight->id)->where('user_id', $viewer->id)->where('event', 'impression')->pluck('package_id')->all();
        foreach ((array) ($insight->output['items'] ?? []) as $item) {
            if (! in_array($item['package_id'], $seen, true)) {
                AiRecommendationEvent::query()->create([
                    'ai_insight_id' => $insight->id, 'package_id' => $item['package_id'], 'user_id' => $viewer->id, 'member_id' => $memberId,
                    'organization_id' => $organizationId, 'context' => $context, 'event' => 'impression', 'rank' => $item['rank'] ?? null,
                ]);
            }
        }
    }

    /** Klik pada rekomendasi yang memang ditampilkan di insight itu. @return string|null slug program */
    public function click(AiInsight $insight, string $slug, User $viewer): ?string
    {
        $item = collect((array) ($insight->output['items'] ?? []))->firstWhere('slug', $slug);
        if (! $item) {
            return null;
        }
        $already = AiRecommendationEvent::query()->where('ai_insight_id', $insight->id)->where('package_id', $item['package_id'])
            ->where('user_id', $viewer->id)->where('event', 'click')->exists();
        if (! $already) {
            $context = $insight->subject_type === (new CorporateUser)->getMorphClass() ? 'corporate' : 'member';
            AiRecommendationEvent::query()->create([
                'ai_insight_id' => $insight->id, 'package_id' => $item['package_id'], 'user_id' => $viewer->id,
                'member_id' => $context === 'member' ? $insight->subject_id : null, 'organization_id' => $insight->organization_id,
                'context' => $context, 'event' => 'click', 'rank' => $item['rank'] ?? null,
            ]);
        }

        return $item['slug'];
    }

    /** @return array<string, mixed> */
    public function payload(AiInsight $i, ?User $viewer): array
    {
        $fb = $viewer ? AiInsightFeedback::query()->where('ai_insight_id', $i->id)->where('user_id', $viewer->id)->value('helpful') : null;
        $map = fn (array $it) => [
            'slug' => $it['slug'], 'title' => $it['title'], 'category' => $it['category'], 'rank' => $it['rank'] ?? null,
            'reasons' => array_column($it['reasons'], 'text'), 'next' => $it['next'],
        ];

        return [
            'insightId' => $i->id,
            'aiGenerated' => true,
            'computedAt' => $i->created_at?->toIso8601String(),
            'items' => array_map($map, (array) ($i->output['items'] ?? [])),
            'groups' => isset($i->output['groups']) ? array_map(fn ($g) => ['position' => $g['position'], 'employees' => $g['employees'], 'items' => array_map($map, $g['items'])], $i->output['groups']) : null,
            'myFeedback' => $fb === null ? null : (bool) $fb,
        ];
    }

    // ------------------------------------------------------------------ data bersama

    /** Program terbit + kategori, prasyarat, kompetensi, popularitas, dan jadwal terdekat yang membuka pendaftaran. @return list<array<string, mixed>> */
    public function catalog(): array
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }
        $popularity = DB::table('enrollments')->join('training_schedules', 'training_schedules.id', '=', 'enrollments.schedule_id')
            ->where('enrollments.status', '!=', 'dropped')->whereNotNull('enrollments.member_id')
            ->groupBy('training_schedules.package_id')->selectRaw('training_schedules.package_id AS id, COUNT(DISTINCT enrollments.member_id) AS n')->pluck('n', 'id');
        $next = TrainingSchedule::query()->upcoming()->get()->filter(fn (TrainingSchedule $s) => $s->acceptsRegistrations())
            ->groupBy('package_id')->map(fn ($g) => $g->first());

        return $this->catalogCache = TrainingPackage::query()->published()->with(['category:id,name', 'prerequisites:training_packages.id', 'competencyLinks:competencies.id'])
            ->orderBy('title')->get()
            ->map(fn (TrainingPackage $p) => [
                'id' => $p->id, 'slug' => $p->slug, 'title' => $p->title, 'category_id' => $p->category_id, 'category' => $p->category?->name,
                'prerequisites' => $p->prerequisites->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
                'competencies' => $p->competencyLinks->mapWithKeys(fn ($c) => [(int) $c->id => (int) $c->pivot->level])->all(),
                'popularity' => (int) ($popularity[$p->id] ?? 0),
                'next' => ($s = $next->get($p->id)) ? ['code' => $s->code, 'dateLabel' => IdDate::long($s->start_date)] : null,
            ])->values()->all();
    }

    /**
     * Pola peserta lain: untuk tiap kandidat, jumlah peserta lain (bukan $exceptMember) yang mengikuti salah satu program
     * $anchors dan juga mengikuti kandidat itu. Hanya hitungan, tanpa identitas.
     *
     * @param  list<int>  $anchors
     * @return array<int, array{count: int, from: string}>
     */
    public function coCounts(array $anchors, ?int $exceptMember): array
    {
        if ($anchors === []) {
            return [];
        }
        $rows = DB::table('enrollments as e1')
            ->join('training_schedules as s1', 's1.id', '=', 'e1.schedule_id')
            ->join('enrollments as e2', 'e2.member_id', '=', 'e1.member_id')
            ->join('training_schedules as s2', 's2.id', '=', 'e2.schedule_id')
            ->join('training_packages as p1', 'p1.id', '=', 's1.package_id')
            ->whereIn('s1.package_id', $anchors)->whereNotIn('s2.package_id', $anchors)
            ->whereNotNull('e1.member_id')->where('e1.status', '!=', 'dropped')->where('e2.status', '!=', 'dropped')
            ->when($exceptMember, fn ($q) => $q->where('e1.member_id', '!=', $exceptMember))
            ->groupBy('s2.package_id', 'p1.title')
            ->selectRaw('s2.package_id AS candidate, p1.title AS anchor, COUNT(DISTINCT e1.member_id) AS n')->get();
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->n > ($out[(int) $r->candidate]['count'] ?? 0)) {
                $out[(int) $r->candidate] = ['count' => (int) $r->n, 'from' => $r->anchor];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ evaluasi

    /**
     * Uji offline (backtest): untuk member dengan 2+ program, program terakhir disembunyikan lalu dicek apakah masuk
     * 3 rekomendasi teratas dari riwayat sebelumnya (hit rate@3), dibanding baseline "terpopuler di bidang yang sama".
     *
     * @return array<string, mixed>
     */
    public function backtest(): array
    {
        $catalog = $this->catalog();
        $ids = array_column($catalog, 'id');
        $rows = DB::table('enrollments')->join('training_schedules', 'training_schedules.id', '=', 'enrollments.schedule_id')
            ->join('training_packages', 'training_packages.id', '=', 'training_schedules.package_id')
            ->whereNotNull('enrollments.member_id')->where('enrollments.status', '!=', 'dropped')
            ->orderBy('training_schedules.start_date')->orderBy('enrollments.id')
            ->get(['enrollments.member_id', 'training_packages.id', 'training_packages.title', 'training_packages.category_id', 'enrollments.status'])
            ->groupBy('member_id')->filter(fn ($g) => $g->pluck('id')->unique()->count() >= 2);
        $model = $base = [];
        foreach ($rows as $memberId => $g) {
            $target = (int) $g->last()->id;
            $history = $g->slice(0, -1)->where('id', '!=', $target)->values();
            if (! in_array($target, $ids, true) || $history->isEmpty()) {
                continue;
            }
            $profile = $this->profileFrom($history, collect());
            $anchors = array_keys($profile['completed']) ?: $profile['taken'];
            $top = array_column($this->recommender->rank($profile, $catalog, $this->coCounts($anchors, (int) $memberId)), 'package_id');
            $model[] = in_array($target, $top, true);
            $base[] = in_array($target, $this->recommender->baseline($profile, $catalog), true);
        }
        $n = count($model);
        $hit = $n ? round(count(array_filter($model)) / $n, 4) : null;
        $baseHit = $n ? round(count(array_filter($base)) / $n, 4) : null;

        return ['n' => $n, 'hit_rate_at_3' => $hit, 'baseline_hit_rate_at_3' => $baseHit,
            'beats_baseline' => $n === 0 ? null : $hit >= $baseHit];
    }

    /**
     * Metrik pemakaian: CTR, konversi (pendaftaran/permintaan training untuk program yang ditampilkan dalam 30 hari),
     * dan repeat enrollment (alumni yang melihat rekomendasi lalu mendaftar program baru dalam 60 hari).
     *
     * @return array<string, mixed>
     */
    public function online(): array
    {
        $imp = AiRecommendationEvent::query()->where('event', 'impression')->get(['member_id', 'organization_id', 'package_id', 'context', 'created_at']);
        $clicks = AiRecommendationEvent::query()->where('event', 'click')->count();
        $firstShown = $imp->groupBy(fn ($e) => $e->context.':'.($e->member_id ?? $e->organization_id).':'.$e->package_id)->map(fn ($g) => $g->sortBy('created_at')->first());
        $converted = 0;
        foreach ($firstShown as $e) {
            $from = CarbonImmutable::parse($e->created_at);
            $until = $from->addDays(self::CONVERSION_DAYS);
            $converted += (int) ($e->context === 'member'
                ? DB::table('registrations')->where('member_id', $e->member_id)->where('package_id', $e->package_id)->whereBetween('created_at', [$from, $until])->exists()
                : DB::table('training_requests')->where('organization_id', $e->organization_id)->where('package_id', $e->package_id)->whereBetween('created_at', [$from, $until])->exists());
        }
        $members = $imp->where('context', 'member')->groupBy('member_id')->map(fn ($g) => CarbonImmutable::parse($g->min('created_at')));
        $alumni = 0;
        $repeat = 0;
        foreach ($members as $memberId => $first) {
            $wasAlumni = DB::table('enrollments')->where('member_id', $memberId)->where('status', 'completed')->where('completed_at', '<', $first)->exists();
            if ($wasAlumni) {
                $alumni++;
                $repeat += (int) DB::table('registrations')->where('member_id', $memberId)->whereBetween('created_at', [$first, $first->addDays(self::REPEAT_DAYS)])->exists();
            }
        }

        return [
            'impressions' => $imp->count(),
            'clicks' => $clicks,
            'ctr' => Metrics::rate($clicks, $imp->count()),
            'shown_pairs' => $firstShown->count(),
            'conversions' => $converted,
            'conversion_rate' => Metrics::rate($converted, $firstShown->count()),
            'alumni_shown' => $alumni,
            'repeat_enrollment_rate' => Metrics::rate($repeat, $alumni),
        ];
    }
}
