<?php

namespace App\Support\Ai;

/**
 * Aturan teks keluaran AI yang tampil ke pengguna (chatbot publik, Asisten Belajar, insight tahap 5I).
 *
 * Prompt sistem sudah melarang em dash (U+2014) dan en dash (U+2013), tetapi model tetap bisa memakainya, jadi
 * teks jawaban selalu dinormalkan secara deterministik sebelum disimpan dan dikirim ke pengguna:
 *  - rentang angka/jam/tanggal "08.00 (dash) 17.00", "2(dash)6" menjadi "08.00-17.00", "2-6";
 *  - dash di awal baris (butir daftar) menjadi "- ";
 *  - dash sebagai pemisah antar kata/kalimat menjadi " - ";
 *  - en dash yang menempel di antara kata ("Senin(dash)Jumat") menjadi "-".
 * Karakter dash ditulis sebagai escape \u{...} agar berkas ini lolos pengaman CI teks.
 */
final class AiText
{
    private const EM = "\u{2014}";

    private const EN = "\u{2013}";

    private const BAR = "\u{2015}";

    public static function normalize(string $text): string
    {
        $dash = '['.self::EM.self::EN.self::BAR.']';

        // 1. Rentang angka: digit, dash, digit (spasi di sekitarnya dibuang).
        $text = (string) preg_replace('/(?<=\d)[ \t]*'.$dash.'[ \t]*(?=\d)/u', '-', $text);
        // 2. Butir daftar di awal baris.
        $text = (string) preg_replace('/^([ \t]*)'.$dash.'[ \t]*/mu', '$1- ', $text);
        // 3. En dash menempel di antara huruf: rentang kata (Senin-Jumat).
        $text = (string) preg_replace('/(?<=\p{L})'.self::EN.'(?=\p{L})/u', '-', $text);
        // 4. Sisanya pemisah: " - ", tanpa menggandakan spasi.
        $text = (string) preg_replace('/[ \t]*'.$dash.'[ \t]*/u', ' - ', $text);

        return $text;
    }
}
