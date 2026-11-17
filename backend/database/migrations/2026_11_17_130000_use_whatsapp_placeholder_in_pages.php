<?php

use App\Support\CmsCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data: nomor WhatsApp yang tertulis langsung di isi halaman "Cara Pendaftaran" (dari seed) diganti
 * placeholder {{whatsapp}} yang diisi frontend dari pengaturan situs, agar ikut berubah saat nomor diganti di admin.
 *
 * Hanya paragraf yang MASIH PERSIS sama dengan teks seed lama yang diubah; bagian lain isi halaman dan halaman yang
 * sudah diedit admin pada paragraf itu tidak disentuh. Idempoten, dan down() mengembalikan paragraf lama.
 */
return new class extends Migration
{
    private const SLUG = 'cara-pendaftaran';

    private const OLD = '<p>Hubungi admin kami di WhatsApp 0851-2125-1285 pada hari kerja pukul 08.00 sampai 17.00 WIB.</p>';

    private const NEW = '<p>Hubungi admin kami di WhatsApp {{whatsapp}} pada hari kerja pukul 08.00 sampai 17.00 WIB.</p>';

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
