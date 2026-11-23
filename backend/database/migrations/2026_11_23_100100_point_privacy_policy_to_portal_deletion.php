<?php

use App\Support\CmsCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data: paragraf hak subjek data di Kebijakan Privasi kini menyebut menu Privasi & Data di portal (unduh data
 * dan ajukan penghapusan sendiri). Hanya paragraf yang MASIH PERSIS sama dengan teks seed lama yang diubah; halaman
 * yang paragrafnya sudah diedit admin tidak disentuh. Idempoten, dan down() mengembalikan paragraf lama.
 */
return new class extends Migration
{
    private const SLUG = 'kebijakan-privasi';

    private const OLD = '<p>Anda berhak mengakses, memperbaiki, atau meminta penghapusan data pribadi Anda, serta menarik persetujuan pemasaran. Ajukan permintaan melalui WhatsApp resmi atau email yang tercantum di halaman Kontak.</p>';

    private const NEW = '<p>Anda berhak mengakses, memperbaiki, atau meminta penghapusan data pribadi Anda, serta menarik persetujuan pemasaran. Salinan data dapat diunduh dan penghapusan data dapat diajukan sendiri di portal member, menu Privasi &amp; Data. Permintaan lain dapat diajukan melalui WhatsApp resmi atau email yang tercantum di halaman Kontak.</p>';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        $page = DB::table('pages')->where('slug', self::SLUG)->first(['id', 'body']);
        if (! $page || ! str_contains((string) $page->body, $from)) {
            return;
        }
        DB::table('pages')->where('id', $page->id)->update(['body' => str_replace($from, $to, (string) $page->body)]);
        CmsCache::flush(['cms', 'pages', 'page:'.self::SLUG]);
    }
};
