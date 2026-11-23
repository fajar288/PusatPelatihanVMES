<?php

namespace App\Support;

/**
 * Perbandingan nilai kolom JSON yang tidak bergantung pada urutan kunci objek.
 *
 * Tipe JSON MySQL menyimpan objek dengan kunci yang diurutkan ulang (kunci pendek dulu, lalu alfabetis), sedangkan
 * PostgreSQL (json), MariaDB (LONGTEXT), dan SQLite menyimpan apa adanya. Isinya sama, jadi perbandingan "berubah atau
 * tidak" harus memakai bentuk kanonik. Urutan elemen daftar (list) tetap diperhitungkan.
 */
final class JsonValue
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = array_map(self::canonical(...), $value);
        if (! array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }
}
