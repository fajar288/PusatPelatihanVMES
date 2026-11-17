<?php

namespace Tests\Feature;

use App\Models\AssessmentRubric;
use App\Models\SiteSetting;
use App\Models\TrainingPackage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migrasi data 2026_11_17_100000_replace_legacy_seeded_texts: hanya teks bawaan yang persis sama yang diganti,
 * teks yang sudah diedit admin tidak berubah, aman dijalankan ulang, dan down() hanya mengembalikan baris yang cocok.
 */
class LegacySeededTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const RUBRIC_OLD = "Rubrik bawaan (PRD \u{00A7}5.9); sesuaikan kriteria dengan kompetensi program.";

    private const RUBRIC_NEW = 'Rubrik bawaan PPTI; sesuaikan kriteria dengan kompetensi program.';

    private const SLUG = 'ppti-mitra-resmi-pearson-vue';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_17_100000_replace_legacy_seeded_texts.php');
    }

    /** Isi artikel versi seed baru, langsung dari seed JSON (sumber kebenaran konten contoh). */
    private function seedPost(): array
    {
        $posts = json_decode((string) file_get_contents(database_path('seeders/data/posts.json')), true);

        return collect($posts)->firstWhere('slug', self::SLUG);
    }

    private function oldBody(): string
    {
        return str_replace('<p>Batam, PPTI', "<p>Batam \u{2014} PPTI", $this->seedPost()['body']);
    }

    /** @return array{0: int, 1: int} [rubrik bawaan, rubrik yang sudah diedit admin] */
    private function rubrics(): array
    {
        $package = TrainingPackage::query()->create(['slug' => 'uji-rubrik', 'title' => 'Uji Rubrik', 'status' => 'published']);
        $default = DB::table('assessment_rubrics')->insertGetId(['package_id' => $package->id, 'name' => 'Rubrik A', 'description' => self::RUBRIC_OLD, 'created_at' => now(), 'updated_at' => now()]);
        $edited = DB::table('assessment_rubrics')->insertGetId(['package_id' => $package->id, 'name' => 'Rubrik B', 'description' => self::RUBRIC_OLD.' Ditambah admin.', 'created_at' => now(), 'updated_at' => now()]);

        return [$default, $edited];
    }

    private function siteSetting(string $name): void
    {
        DB::table('site_settings')->insert(['key' => 'site', 'group' => 'site', 'value' => json_encode(['name' => $name, 'shortName' => 'VMES Training Center', 'logo' => '/images/logo.jpeg']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertPost(string $body): void
    {
        $seed = $this->seedPost();
        DB::table('posts')->insert(['type' => 'news', 'slug' => self::SLUG, 'title' => $seed['title'], 'excerpt' => $seed['excerpt'], 'body' => $body, 'status' => 'published', 'seo_title' => 'SEO tetap', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rubricText(int $id): ?string
    {
        return DB::table('assessment_rubrics')->where('id', $id)->value('description');
    }

    private function site(): array
    {
        return json_decode((string) DB::table('site_settings')->where('key', 'site')->value('value'), true);
    }

    private function postRow(): object
    {
        return DB::table('posts')->where('slug', self::SLUG)->first();
    }

    public function test_up_replaces_exact_legacy_texts_and_is_idempotent(): void
    {
        [$default, $edited] = $this->rubrics();
        $this->siteSetting("PPTI \u{2013} VMES Training Center");
        $this->insertPost($this->oldBody());
        $seed = $this->seedPost();

        $migration = $this->migration();
        $migration->up();

        $this->assertSame(self::RUBRIC_NEW, $this->rubricText($default));
        $this->assertSame(self::RUBRIC_OLD.' Ditambah admin.', $this->rubricText($edited));
        // teks rubrik baru dari migrasi sama dengan teks untuk rubrik baru di model
        $package = TrainingPackage::query()->create(['slug' => 'uji-rubrik-2', 'title' => 'Uji Rubrik 2', 'status' => 'published']);
        $this->assertSame(self::RUBRIC_NEW, AssessmentRubric::activeFor($package)->description);

        $site = $this->site();
        $this->assertSame('PPTI - VMES Training Center', $site['name']);
        $this->assertSame('VMES Training Center', $site['shortName'], 'field lain di JSON tidak berubah');
        $this->assertSame('/images/logo.jpeg', $site['logo']);
        $this->assertSame('PPTI - VMES Training Center', SiteSetting::get('site')['name'], 'cache pengaturan ikut dibersihkan');

        $post = $this->postRow();
        $this->assertSame($seed['body'], $post->body, 'isi artikel sama dengan seed baru');
        $this->assertSame($seed['title'], $post->title);
        $this->assertSame($seed['excerpt'], $post->excerpt);
        $this->assertSame('SEO tetap', $post->seo_title);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $post->body);

        // dijalankan ulang: tidak ada yang berubah lagi
        $before = [$this->rubricText($default), $this->rubricText($edited), $this->site(), $this->postRow()->body];
        $migration->up();
        $this->assertSame($before, [$this->rubricText($default), $this->rubricText($edited), $this->site(), $this->postRow()->body]);
    }

    public function test_up_leaves_texts_edited_by_admin_untouched(): void
    {
        $this->siteSetting("PPTI \u{2013} VMES Batam");
        $editedBody = $this->oldBody().'<p>Paragraf tambahan admin.</p>';
        $this->insertPost($editedBody);

        $this->migration()->up();

        $this->assertSame("PPTI \u{2013} VMES Batam", $this->site()['name']);
        $this->assertSame($editedBody, $this->postRow()->body);
    }

    public function test_down_restores_only_rows_matching_the_new_text(): void
    {
        [$default, $edited] = $this->rubrics();
        $this->siteSetting("PPTI \u{2013} VMES Training Center");
        $this->insertPost($this->oldBody());

        $migration = $this->migration();
        $migration->up();
        // admin mengedit rubrik bawaan setelah migrasi: down() tidak boleh menimpanya
        DB::table('assessment_rubrics')->where('id', $default)->update(['description' => self::RUBRIC_NEW.' Revisi admin.']);

        $migration->down();
        $migration->down(); // aman dijalankan ulang

        $this->assertSame(self::RUBRIC_NEW.' Revisi admin.', $this->rubricText($default));
        $this->assertSame(self::RUBRIC_OLD.' Ditambah admin.', $this->rubricText($edited));
        $this->assertSame("PPTI \u{2013} VMES Training Center", $this->site()['name']);
        $this->assertSame($this->oldBody(), $this->postRow()->body);
    }

    public function test_up_without_matching_rows_changes_nothing(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(0, DB::table('assessment_rubrics')->count());
        $this->assertNull(DB::table('site_settings')->where('key', 'site')->first());
        $this->assertNull(DB::table('posts')->where('slug', self::SLUG)->first());
    }
}
