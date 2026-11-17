<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Migrasi data bagian "Pemrosesan dengan Kecerdasan Buatan (AI)" di Kebijakan Privasi: hasil = seed baru, idempoten, reversibel. */
class PrivacyPolicyAiSectionTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_120100_add_ai_section_to_privacy_policy.php');
    }

    public function test_old_stored_policy_becomes_the_new_seed_and_back(): void
    {
        $seed = collect(json_decode((string) file_get_contents(database_path('seeders/data/pages.json')), true))->firstWhere('slug', 'kebijakan-privasi')['body'];
        $this->assertStringContainsString('Kecerdasan Buatan (AI)', $seed);
        $start = strpos($seed, '<h2>7. Pemrosesan');
        $end = strpos($seed, '<h2>8. Perubahan Kebijakan</h2>');
        $old = substr($seed, 0, $start).'<h2>7. Perubahan Kebijakan</h2>'.substr($seed, $end + strlen('<h2>8. Perubahan Kebijakan</h2>'));

        DB::table('pages')->insert(['slug' => 'kebijakan-privasi', 'title' => 'Kebijakan Privasi', 'body' => $old, 'created_at' => now(), 'updated_at' => now()]);
        $body = fn () => DB::table('pages')->where('slug', 'kebijakan-privasi')->value('body');

        $this->migration()->up();
        $this->assertSame($seed, $body());
        $this->migration()->up();
        $this->assertSame($seed, $body(), 'menjalankan ulang tidak menggandakan bagian AI');

        $this->migration()->down();
        $this->assertSame($old, $body());
    }

    public function test_edited_policy_without_the_heading_is_left_alone(): void
    {
        DB::table('pages')->insert(['slug' => 'kebijakan-privasi', 'title' => 'Kebijakan Privasi', 'body' => '<p>Versi admin</p>', 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertSame('<p>Versi admin</p>', DB::table('pages')->where('slug', 'kebijakan-privasi')->value('body'));
    }
}
