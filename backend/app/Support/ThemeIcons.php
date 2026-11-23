<?php

namespace App\Support;

/**
 * Ikon sprite tema website yang boleh dipilih admin untuk konten (fitur hero, statistik, kartu keunggulan, fasilitas,
 * layanan). Nama HARUS ada di ICON_NAMES frontend/src/components/ui/icons.ts (dijaga ThemeIconsTest). Ikon media sosial
 * (yt, fb, tw, ig, in) tidak termasuk: dipilih di menu Identitas & kontak.
 */
final class ThemeIcons
{
    /** nama sprite => label yang mudah dibaca admin, urutan sama dengan sprite. */
    public const LABELS = [
        'users' => 'Orang / peserta',
        'shield' => 'Perisai / sertifikasi',
        'cloud' => 'Awan / online',
        'laptop' => 'Laptop',
        'award' => 'Medali penghargaan',
        'grad' => 'Topi wisuda / lulusan',
        'trophy' => 'Piala',
        'spark' => 'Kilau / baru',
        'msg' => 'Gelembung chat',
        'arr' => 'Panah kanan',
        'network' => 'Jaringan',
        'server' => 'Server',
        'db' => 'Database',
        'code' => 'Kode program',
        'brief' => 'Tas kerja / korporat',
        'boxes' => 'Kotak / modul',
        'cpu' => 'Chip / CPU',
        'bot' => 'Robot',
        'factory' => 'Pabrik / industri',
        'chart' => 'Grafik batang',
        'wrench' => 'Kunci pas / teknik',
        'zap' => 'Petir / listrik',
        'rocket' => 'Roket',
        'cal' => 'Kalender',
        'globe' => 'Globe / internasional',
        'arrl' => 'Panah kiri',
        'clock' => 'Jam',
        'monitor' => 'Monitor',
        'star' => 'Bintang',
        'quote' => 'Tanda kutip',
        'check' => 'Centang',
        'pin' => 'Pin lokasi',
        'phone' => 'Telepon',
        'mail' => 'Surat / email',
    ];

    /** @return array<string, string> pilihan Select: label diikuti nama sprite agar mudah dicari. */
    public static function options(): array
    {
        $out = [];
        foreach (self::LABELS as $name => $label) {
            $out[$name] = "$label ($name)";
        }

        return $out;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }
}
