<?php

namespace App\Support;

use App\Filament\Pages\CompanyProfileSettings;
use App\Filament\Pages\HomepageSettings;
use App\Filament\Pages\PostCategorySettings;
use App\Filament\Pages\SiteIdentitySettings;
use App\Models\AuditLog;
use App\Models\SiteSetting;

/**
 * Pengaturan CMS berbentuk JSON di tabel site_settings yang disunting lewat editor terstruktur. Bentuk tiap kunci HARUS
 * sama dengan tipe di frontend/src/lib/cms-types.ts: editor hanya mengubah nilai, tidak menambah atau menghapus kunci,
 * dan urutan kunci objek dipertahankan.
 *
 * Notasi bentuk: 'string' | 'int' untuk nilai skalar, array asosiatif untuk objek (urutan kunci = urutan tersimpan),
 * dan [bentukItem] (array dengan satu elemen di indeks 0) untuk daftar.
 */
final class CmsSettings
{
    /** Bentuk yang tersimpan. Kunci `hero` tidak memuat slides/officialPartners: keduanya dari tabel Banner dan Mitra. */
    public const SHAPES = [
        // HeroData tanpa slides & officialPartners (digabung HomeController dari Banner posisi hero dan Mitra resmi)
        'hero' => [
            'pill' => 'string', 'titleLine1' => 'string', 'titleLine2' => 'string', 'lead' => 'string',
            'features' => [['icon' => 'string', 'label' => 'string']],
            'glassStat' => ['icon' => 'string', 'title' => 'string', 'sub' => 'string'],
        ],
        // StatItem[]
        'stats' => [['icon' => 'string', 'n' => 'int', 'suffix' => 'string', 'label' => 'string']],
        // AboutData
        'about' => ['eyebrow' => 'string', 'titlePrefix' => 'string', 'titleEm' => 'string', 'paragraphs' => ['string'], 'tagline' => 'string'],
        // WhyData
        'why' => ['eyebrow' => 'string', 'title' => 'string', 'sub' => 'string', 'items' => [['icon' => 'string', 'title' => 'string', 'desc' => 'string']]],
        // CtaData
        'cta' => ['eyebrow' => 'string', 'title' => 'string', 'desc' => 'string', 'points' => ['string']],
        // CompanyProfile
        'company' => [
            'vision' => 'string',
            'missions' => ['string'],
            'facilities' => [['icon' => 'string', 'title' => 'string', 'desc' => 'string']],
            'trainers' => [['name' => 'string', 'role' => 'string', 'certs' => 'string', 'initials' => 'string']],
            'services' => [['icon' => 'string', 'title' => 'string', 'desc' => 'string', 'href' => 'string']],
            'partnership_points' => ['string'],
        ],
        // POST_CATEGORIES (frontend/src/data/articles.ts): slug & category tetap, judul & deskripsi bisa diedit
        'post_categories' => [['slug' => 'string', 'category' => 'string', 'title' => 'string', 'desc' => 'string']],
    ];

    /** Kunci yang ditampilkan di halaman editor Beranda (satu tab per kunci). */
    public const HOME_KEYS = ['hero', 'stats', 'about', 'why', 'cta'];

    /** Grup site_settings.group saat menyimpan (sama dengan seeder). */
    private const GROUP = 'site';

    /** @return array<string, mixed>|list<mixed> nilai tersimpan; kosong bila belum ada */
    public static function current(string $key): array
    {
        return (array) (SiteSetting::query()->where('key', $key)->value('value') ?? []);
    }

    /** Kunci yang punya editor terstruktur (termasuk `site`). */
    public static function hasEditor(string $key): bool
    {
        return $key === SiteIdentity::KEY || array_key_exists($key, self::SHAPES);
    }

    /** URL halaman editor terstruktur untuk kunci; null bila belum ada editornya. */
    public static function editorUrl(string $key): ?string
    {
        return match (true) {
            $key === SiteIdentity::KEY => SiteIdentitySettings::getUrl(),
            in_array($key, self::HOME_KEYS, true) => HomepageSettings::getUrl(['tab' => $key]),
            $key === 'company' => CompanyProfileSettings::getUrl(),
            $key === 'post_categories' => PostCategorySettings::getUrl(),
            default => null,
        };
    }

    /** Nama editor untuk tombol "Buka editor ...". */
    public static function editorLabel(string $key): ?string
    {
        return match (true) {
            $key === SiteIdentity::KEY => 'Identitas & kontak',
            in_array($key, self::HOME_KEYS, true) => 'Beranda',
            $key === 'company' => 'Profil perusahaan',
            $key === 'post_categories' => 'Kategori artikel',
            default => null,
        };
    }

    /**
     * Rapikan isian form ke bentuk tersimpan: hanya kunci yang dikenal, urutan kunci tetap, tipe sesuai bentuk,
     * item daftar yang kosong dibuang, dan kunci repeater (UUID) dihapus.
     *
     * @return array<string, mixed>|list<mixed>
     */
    public static function normalize(string $key, mixed $input): array
    {
        return (array) self::shape(self::SHAPES[$key], $input);
    }

    private static function shape(mixed $shape, mixed $value): mixed
    {
        if ($shape === 'string') {
            // Tidak di-trim: spasi bisa bermakna (mis. titlePrefix 'Tentang ' disambung dengan titleEm).
            return is_scalar($value) ? (string) $value : '';
        }
        if ($shape === 'int') {
            return is_numeric($value) ? (int) $value : (int) preg_replace('/\D+/', '', (string) (is_scalar($value) ? $value : ''));
        }
        if (array_is_list($shape)) {
            $items = [];
            foreach (array_values(is_array($value) ? $value : []) as $item) {
                $item = self::shape($shape[0], $item);
                if (! self::isBlank($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }
        $value = is_array($value) ? $value : [];
        $out = [];
        foreach ($shape as $field => $fieldShape) {
            $out[$field] = self::shape($fieldShape, $value[$field] ?? null);
        }

        return $out;
    }

    /**
     * Slug dan kategori artikel dipakai sebagai URL halaman (/tutorial, /berita, ...) dan jenis postingan, jadi tidak
     * bisa diubah dari editor: daftar dan urutannya tetap, hanya judul & deskripsi yang diambil dari isian.
     *
     * @param  list<array<string, string>>  $input
     * @param  list<array<string, string>>  $existing
     * @return list<array<string, string>>
     */
    private static function lockCategories(array $input, array $existing): array
    {
        $bySlug = array_column($input, null, 'slug');
        $out = [];
        foreach (array_values($existing) as $i => $c) {
            // cocokkan per slug; bila slug di isian diubah, pakai item di posisi yang sama (daftar tidak bisa diurutkan)
            $in = $bySlug[$c['slug']] ?? ($input[$i] ?? []);
            $out[] = [
                'slug' => $c['slug'],
                'category' => $c['category'],
                'title' => trim((string) ($in['title'] ?? '')) !== '' ? $in['title'] : $c['title'],
                'desc' => trim((string) ($in['desc'] ?? '')) !== '' ? $in['desc'] : $c['desc'],
            ];
        }

        return $out;
    }

    /** Item daftar dianggap kosong bila semua teksnya kosong (angka diabaikan agar baris statistik kosong ikut terbuang). */
    private static function isBlank(mixed $item): bool
    {
        if (is_string($item)) {
            return trim($item) === '';
        }
        if (is_array($item)) {
            foreach ($item as $v) {
                if (! is_int($v) && ! self::isBlank($v)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Simpan satu kunci lewat model (memicu CmsObserver: flush cache API + revalidasi tag frontend "settings" dan
     * "home") dan catat audit bila berubah. Tidak menulis apa pun bila nilainya sama.
     *
     * @return bool true bila ada perubahan
     */
    public static function save(string $key, mixed $input): bool
    {
        $before = self::current($key);
        $value = self::normalize($key, $input);
        if ($key === 'post_categories' && $before !== []) {
            $value = self::lockCategories($value, $before);
        }
        if (JsonValue::same($value, $before)) {
            return false;
        }
        SiteSetting::set($key, $value, self::GROUP);
        AuditLog::record("settings.{$key}_updated", null, $before === [] ? null : ['value' => $before], ['value' => $value]);

        return true;
    }
}
