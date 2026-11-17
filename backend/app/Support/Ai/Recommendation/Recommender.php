<?php

namespace App\Support\Ai\Recommendation;

/**
 * Peringkat rekomendasi training yang deterministik (tanpa LLM). Setiap poin berasal dari sumber yang bisa ditelusuri:
 *
 *   prasyarat     +40  program lanjutan dari program yang sudah diluluskan ("Anda lulus PLC Dasar")
 *   gap           +gap kompetensi posisi yang masih di bawah target, maks. 30 ("Gap SCADA 18 poin untuk posisi Teknisi")
 *   pola peserta  +5..25 peserta lain yang mengikuti program yang sama juga mengikuti program ini (minimal 3 orang)
 *   bidang        +10  satu kategori dengan program yang pernah diikuti
 *   populer       +1..5 hanya pengisi bila sumber lain kurang (peserta baru), alasannya jumlah peserta
 *
 * Program yang sudah diikuti/didaftarkan, atau yang prasyaratnya belum diluluskan, tidak disarankan.
 * Urutan: skor, lalu popularitas, lalu judul (stabil, sama untuk input yang sama).
 */
final class Recommender
{
    public const PREREQUISITE_POINTS = 40;

    public const GAP_MAX = 30;

    public const CO_MAX = 25;

    public const CATEGORY_POINTS = 10;

    /** Minimal peserta lain agar pola "peserta yang mengikuti X juga mengikuti Y" ditampilkan (tidak menunjuk orang tertentu). */
    public const MIN_SUPPORT = 3;

    /**
     * @param  array{completed: array<int, string>, taken: list<int>, categories: array<int, string>, gaps: array<int, array{gap: int, name: string, position: ?string}>}  $profile
     * @param  list<array<string, mixed>>  $catalog  item: id, slug, title, category_id, category, prerequisites (list<int>), competencies (array<int,int>), popularity, next
     * @param  array<int, array{count: int, from: string}>  $co  per kandidat: jumlah peserta lain + program asal
     * @return list<array<string, mixed>>
     */
    public function rank(array $profile, array $catalog, array $co, int $limit = 3, bool $fillPopular = true): array
    {
        $taken = array_flip($profile['taken']);
        $scored = [];
        foreach ($catalog as $p) {
            if (isset($taken[$p['id']])) {
                continue;
            }
            $missing = array_diff($p['prerequisites'], array_keys($profile['completed']));
            if ($missing !== []) {
                continue;
            }
            $reasons = [];
            if ($p['prerequisites'] !== []) {
                $names = array_map(fn ($id) => $profile['completed'][$id], $p['prerequisites']);
                $reasons[] = ['source' => 'prerequisite', 'points' => self::PREREQUISITE_POINTS, 'text' => 'Anda lulus '.implode(', ', $names).'; program ini kelanjutannya'];
            }
            $bestGap = null;
            foreach ($p['competencies'] as $cid => $level) {
                $g = $profile['gaps'][$cid] ?? null;
                if ($g && $g['gap'] > 0 && (! $bestGap || $g['gap'] > $bestGap['gap'])) {
                    $bestGap = $g;
                }
            }
            if ($bestGap) {
                $reasons[] = ['source' => 'gap', 'points' => min(self::GAP_MAX, $bestGap['gap']),
                    'text' => "Gap kompetensi {$bestGap['name']} {$bestGap['gap']} poin".($bestGap['position'] ? " untuk posisi {$bestGap['position']}" : '')];
            }
            $c = $co[$p['id']] ?? null;
            if ($c && $c['count'] >= self::MIN_SUPPORT) {
                $reasons[] = ['source' => 'co_enrollment', 'points' => min(self::CO_MAX, 5 + 2 * $c['count']),
                    'text' => "{$c['count']} peserta yang mengikuti {$c['from']} juga mengikuti program ini"];
            }
            if ($p['category_id'] !== null && isset($profile['categories'][$p['category_id']])) {
                $reasons[] = ['source' => 'category', 'points' => self::CATEGORY_POINTS, 'text' => 'Satu bidang dengan '.$profile['categories'][$p['category_id']]];
            }
            if ($reasons !== []) {
                $scored[] = $this->item($p, $reasons);
            }
        }
        $this->sort($scored);
        $out = array_slice($scored, 0, $limit);

        if ($fillPopular && count($out) < $limit) {
            $chosen = array_flip(array_column($out, 'package_id'));
            $popular = array_values(array_filter($catalog, fn ($p) => ! isset($taken[$p['id']]) && ! isset($chosen[$p['id']])
                && array_diff($p['prerequisites'], array_keys($profile['completed'])) === [] && $p['popularity'] >= self::MIN_SUPPORT));
            usort($popular, fn ($a, $b) => [$b['popularity'], $a['title']] <=> [$a['popularity'], $b['title']]);
            foreach (array_slice($popular, 0, $limit - count($out)) as $p) {
                $out[] = $this->item($p, [['source' => 'popular', 'points' => min(5, max(1, intdiv($p['popularity'], 10) + 1)), 'text' => "Diikuti {$p['popularity']} peserta"]]);
            }
        }

        return array_values(array_map(fn ($r, $i) => $r + ['rank' => $i + 1], $out, array_keys($out)));
    }

    /**
     * Baseline pembanding: program terpopuler di kategori yang pernah diikuti (atau terpopuler secara umum).
     *
     * @param  array{completed: array<int, string>, taken: list<int>, categories: array<int, string>}  $profile
     * @param  list<array<string, mixed>>  $catalog
     * @return list<int> package id
     */
    public function baseline(array $profile, array $catalog, int $limit = 3): array
    {
        $taken = array_flip($profile['taken']);
        $open = array_values(array_filter($catalog, fn ($p) => ! isset($taken[$p['id']])));
        usort($open, fn ($a, $b) => [isset($profile['categories'][$b['category_id']]) ? 1 : 0, $b['popularity'], $a['title']]
            <=> [isset($profile['categories'][$a['category_id']]) ? 1 : 0, $a['popularity'], $b['title']]);

        return array_slice(array_column($open, 'id'), 0, $limit);
    }

    /** @param  list<array{source: string, points: int, text: string}>  $reasons */
    private function item(array $p, array $reasons): array
    {
        usort($reasons, fn ($a, $b) => $b['points'] <=> $a['points']);

        return [
            'package_id' => $p['id'],
            'slug' => $p['slug'],
            'title' => $p['title'],
            'category' => $p['category'],
            'score' => array_sum(array_column($reasons, 'points')),
            'popularity' => $p['popularity'],
            'reasons' => $reasons,
            'next' => $p['next'],
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function sort(array &$items): void
    {
        usort($items, fn ($a, $b) => [$b['score'], $b['popularity'], $a['title']] <=> [$a['score'], $a['popularity'], $b['title']]);
    }
}
