<?php

use App\Support\CmsCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data: halaman Kebijakan Privasi yang sudah tersimpan mendapat bagian baru "7. Pemrosesan dengan Kecerdasan
 * Buatan (AI)" (sama dengan seed baru, sumbernya frontend/src/data/pages.ts). Bagian ini DRAF yang wajib ditinjau legal.
 *
 * Aturan: bagian disisipkan tepat sebelum judul "7. Perubahan Kebijakan", yang dinomori ulang menjadi 8. Isi lain halaman
 * (termasuk suntingan admin) tidak diubah. Bila judul itu tidak ada atau bagian AI sudah ada, tidak ada yang diubah, jadi
 * aman diulang. down() menghapus bagian yang persis sama dan mengembalikan nomor judul.
 */
return new class extends Migration
{
    private const SLUG = 'kebijakan-privasi';

    private const OLD_HEADING = '<h2>7. Perubahan Kebijakan</h2>';

    private const NEW_HEADING = '<h2>8. Perubahan Kebijakan</h2>';

    private static function section(): string
    {
        return '<h2>7. Pemrosesan dengan Kecerdasan Buatan (AI)</h2>'."\n"
            .'<p>Beberapa layanan kami dapat memakai kecerdasan buatan (AI) untuk membantu tim PPTI, misalnya menjawab pertanyaan umum di situs, menyarankan program pelatihan, mengenali peserta yang mungkin perlu pendampingan tambahan, dan merangkum umpan balik survei. Fitur ini dinyalakan bertahap setelah diuji.</p>'."\n"
            .'<ul>'
            .'<li>AI tidak mengambil keputusan resmi. Kelulusan, nilai, sertifikat, pendaftaran, dan pembayaran tetap diputuskan oleh tim PPTI.</li>'
            .'<li>Data yang dikirim ke penyedia AI dibatasi seperlunya. Nama, email, nomor telepon, NIK, dan alamat tidak dikirim; peserta diwakili kode internal, dan komentar survei disaring dari email serta nomor telepon sebelum diproses.</li>'
            .'<li>Asisten belajar hanya memakai materi pelatihan yang sudah disetujui dan pertanyaan Anda, tanpa riwayat nilai atau data pribadi lain.</li>'
            .'<li>Penyedia AI kami adalah Anthropic (layanan Claude). Data yang dikirim diproses atas permintaan kami dan dapat diproses di server penyedia di luar Indonesia.</li>'
            .'<li>Hasil AI diberi label "Dibuat AI" dan dicatat beserta versi model yang dipakai. Hasil yang berisiko ditinjau petugas sebelum ditampilkan, dan insight untuk perusahaan pengirim hanya mencakup pelatihan yang mereka sponsori.</li>'
            .'<li>Anda dapat bertanya atau menyampaikan keberatan atas pemrosesan AI melalui kontak di halaman Kontak.</li>'
            .'</ul>'."\n";
    }

    public function up(): void
    {
        $page = DB::table('pages')->where('slug', self::SLUG)->first();
        if (! $page || ! str_contains((string) $page->body, self::OLD_HEADING) || str_contains((string) $page->body, 'Kecerdasan Buatan (AI)</h2>')) {
            return;
        }
        $body = preg_replace('/'.preg_quote(self::OLD_HEADING, '/').'/', self::section().self::NEW_HEADING, (string) $page->body, 1);
        DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
        CmsCache::flush(['cms', 'pages', 'page:'.self::SLUG]);
    }

    public function down(): void
    {
        $page = DB::table('pages')->where('slug', self::SLUG)->first();
        $block = self::section().self::NEW_HEADING;
        if (! $page || ! str_contains((string) $page->body, $block)) {
            return;
        }
        DB::table('pages')->where('id', $page->id)->update(['body' => str_replace($block, self::OLD_HEADING, (string) $page->body)]);
        CmsCache::flush(['cms', 'pages', 'page:'.self::SLUG]);
    }
};
