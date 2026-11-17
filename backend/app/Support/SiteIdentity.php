<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Pengaturan `site` (identitas & kontak situs). Strukturnya HARUS sama dengan tipe SiteSettings di
 * frontend/src/lib/cms-types.ts: editor admin hanya mengubah nilai, tidak menambah/menghapus kunci.
 */
final class SiteIdentity
{
    public const KEY = 'site';

    /** Urutan kunci sama dengan SiteSettings (cms-types.ts). */
    public const KEYS = [
        'name', 'shortName', 'company', 'description', 'logo', 'logoSize', 'waNumber', 'waDisplay', 'waDefaultText',
        'address', 'mapsUrl', 'youtubeProfileId', 'footerDescription', 'tagline', 'socials',
    ];

    /** Ikon media sosial yang dikenali frontend (sprite #i-yt dst.). */
    public const SOCIAL_ICONS = ['yt' => 'YouTube', 'fb' => 'Facebook', 'tw' => 'X / Twitter', 'ig' => 'Instagram', 'in' => 'LinkedIn'];

    /** Nomor WhatsApp internasional tanpa "+" atau spasi: 62 lalu 8-12 digit (mis. 6285121251285). */
    public const WA_NUMBER_REGEX = '/^62[1-9][0-9]{7,11}$/';

    /** ID video YouTube 11 karakter. */
    public const YOUTUBE_ID_REGEX = '/^[A-Za-z0-9_-]{11}$/';

    /** @return array<string, mixed> nilai tersimpan (kosong bila belum ada) */
    public static function current(): array
    {
        return (array) (SiteSetting::query()->where('key', self::KEY)->value('value') ?? []);
    }

    /**
     * Gabungkan isian form ke nilai tersimpan dengan struktur tetap: hanya kunci KEYS, tipe sesuai SiteSettings,
     * kunci yang tidak diedit di form (logo, logoSize) dipertahankan.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public static function normalize(array $input, array $existing): array
    {
        $merged = array_merge($existing, array_intersect_key($input, array_flip(self::KEYS)));
        $out = [];
        foreach (self::KEYS as $key) {
            $value = $merged[$key] ?? null;
            $out[$key] = match ($key) {
                'logoSize' => ['w' => (int) ($value['w'] ?? 0), 'h' => (int) ($value['h'] ?? 0)],
                'socials' => array_values(array_map(fn (array $s) => [
                    'icon' => (string) ($s['icon'] ?? ''),
                    'href' => trim((string) ($s['href'] ?? '')),
                    'label' => trim((string) ($s['label'] ?? '')),
                ], array_filter((array) $value, fn ($s) => is_array($s) && filled($s['href'] ?? null)))),
                'waNumber' => preg_replace('/\D+/', '', (string) $value),
                default => trim((string) $value),
            };
        }

        return $out;
    }

    /**
     * Simpan lewat model (memicu CmsObserver: flush cache API + revalidasi tag frontend "settings" dan "home").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function save(array $input): array
    {
        $value = self::normalize($input, self::current());
        SiteSetting::set(self::KEY, $value, 'site');

        return $value;
    }
}
