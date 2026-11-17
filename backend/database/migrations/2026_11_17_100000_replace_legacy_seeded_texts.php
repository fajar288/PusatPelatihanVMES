<?php

use App\Support\CmsCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data: teks bawaan seeder/aplikasi yang sudah tersimpan di database disamakan dengan versi baru
 * (tanpa rujukan dokumen internal, tanpa em dash / en dash).
 *
 * Aturan: hanya baris yang teksnya MASIH PERSIS sama dengan teks lama yang diubah. Teks yang sudah diedit admin
 * tidak disentuh. Idempoten: setelah diubah, teks lama tidak lagi cocok sehingga menjalankan ulang tidak mengubah apa
 * pun. down() mengembalikan teks lama, juga hanya untuk baris yang persis sama dengan teks baru.
 *
 * - assessment_rubrics.description: deskripsi rubrik bawaan (AssessmentRubric::activeFor).
 * - site_settings key "site": field "name" di dalam JSON; field lain tidak diubah.
 * - posts slug "ppti-mitra-resmi-pearson-vue": judul, ringkasan, dan isi dibandingkan dengan seed lama. Pada seed lama
 *   hanya isinya yang memuat em dash; judul dan ringkasannya sudah sama dengan seed baru, jadi tidak ada yang diubah.
 */
return new class extends Migration
{
    private const RUBRIC_OLD = "Rubrik bawaan (PRD \u{00A7}5.9); sesuaikan kriteria dengan kompetensi program.";

    private const RUBRIC_NEW = 'Rubrik bawaan PPTI; sesuaikan kriteria dengan kompetensi program.';

    private const SITE_NAME_OLD = "PPTI \u{2013} VMES Training Center";

    private const SITE_NAME_NEW = 'PPTI - VMES Training Center';

    private const POST_SLUG = 'ppti-mitra-resmi-pearson-vue';

    /** Isi artikel versi seed baru (backend/database/seeders/data/posts.json). */
    private static function postBodyNew(): string
    {
        return implode("\n", [
            '<p>Batam, PPTI - VMES Training Center resmi ditunjuk sebagai Pearson VUE Authorized Test Center. Dengan status ini, peserta training tidak perlu lagi menempuh perjalanan ke kota lain untuk mengikuti ujian sertifikasi internasional.</p>',
            '<p>Ujian yang tersedia mencakup Cisco (CCNA, CCNP), AWS Certified, CompTIA, dan vendor lain yang bermitra dengan Pearson VUE. Pendaftaran ujian dapat dilakukan melalui tim admin PPTI atau langsung di portal Pearson VUE dengan memilih lokasi Batam.</p>',
            '<h2>Fasilitas Ruang Ujian</h2>',
            '<ul><li>8 workstation dengan pengawasan CCTV sesuai standar Pearson VUE</li><li>Ruang tunggu dan loker peserta</li><li>Jadwal ujian Senin sampai Sabtu</li></ul>',
            '<p>Informasi jadwal ujian dan voucher dapat ditanyakan melalui WhatsApp resmi PPTI - VMES.</p>',
        ]);
    }

    /** Isi artikel versi seed lama: sama persis kecuali pembuka "Batam" yang memakai em dash. */
    private static function postBodyOld(): string
    {
        return str_replace('<p>Batam, PPTI', "<p>Batam \u{2014} PPTI", self::postBodyNew());
    }

    public function up(): void
    {
        $this->apply(
            rubric: [self::RUBRIC_OLD, self::RUBRIC_NEW],
            siteName: [self::SITE_NAME_OLD, self::SITE_NAME_NEW],
            postBody: [self::postBodyOld(), self::postBodyNew()],
        );
    }

    public function down(): void
    {
        $this->apply(
            rubric: [self::RUBRIC_NEW, self::RUBRIC_OLD],
            siteName: [self::SITE_NAME_NEW, self::SITE_NAME_OLD],
            postBody: [self::postBodyNew(), self::postBodyOld()],
        );
    }

    /**
     * @param  array{0: string, 1: string}  $rubric  [dari, ke]
     * @param  array{0: string, 1: string}  $siteName  [dari, ke]
     * @param  array{0: string, 1: string}  $postBody  [dari, ke]
     */
    private function apply(array $rubric, array $siteName, array $postBody): void
    {
        $changed = 0;

        $changed += DB::table('assessment_rubrics')->where('description', $rubric[0])->update(['description' => $rubric[1]]);

        $changed += DB::table('posts')->where('slug', self::POST_SLUG)->where('body', $postBody[0])->update(['body' => $postBody[1]]);

        // JSON dibaca lalu ditulis ulang seperti cast "array" Laravel; bentuk teks JSON tersimpan bisa berbeda antar
        // database (SQLite/MariaDB menyimpan apa adanya, MySQL menormalkan), jadi yang dibandingkan nilai field-nya.
        $site = DB::table('site_settings')->where('key', 'site')->first();
        $value = $site ? json_decode((string) $site->value, true) : null;
        if (is_array($value) && ($value['name'] ?? null) === $siteName[0]) {
            $value['name'] = $siteName[1];
            $changed += DB::table('site_settings')->where('id', $site->id)->update(['value' => json_encode($value)]);
        }

        if ($changed > 0) {
            CmsCache::flush(['cms', 'posts', 'post:'.self::POST_SLUG]);
        }
    }
};
