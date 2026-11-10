<?php

namespace Tests\Feature\Api;

use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Data seed berisi jadwal Nov 2026 – Jan 2027; "hari ini" dikunci agar tes stabil sepanjang waktu.
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed(CmsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_aggregate_has_all_sections(): void
    {
        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonStructure(['data' => ['site', 'hero' => ['slides', 'officialPartners'], 'stats', 'about', 'courses', 'categories', 'schedules', 'partners', 'why', 'testimonials', 'gallery', 'articles', 'cta']])
            ->assertJsonCount(6, 'data.courses')
            ->assertJsonCount(8, 'data.categories')
            ->assertJsonCount(8, 'data.schedules')
            ->assertJsonCount(16, 'data.partners')
            ->assertJsonCount(4, 'data.hero.officialPartners')
            ->assertJsonCount(6, 'data.gallery')
            ->assertJsonCount(3, 'data.articles')
            ->assertJsonPath('data.site.waNumber', '6285121251285');
    }

    public function test_packages_list_filter_and_sort(): void
    {
        $this->getJson('/api/v1/packages')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.slug', 'cisco-ccna')
            ->assertJsonPath('data.0.mode', 'online / offline')
            ->assertJsonPath('data.0.status', 'ok')
            ->assertJsonPath('meta.categories', ['Cloud Computing', 'Cyber Security', 'DevOps', 'Networking', 'Programming']);

        $this->getJson('/api/v1/packages?kategori=Networking&urut=lo')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'mikrotik-mtcna');

        $this->getJson('/api/v1/packages?q=aws')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/packages?mode=online')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_package_detail_with_schedules_and_related(): void
    {
        $this->getJson('/api/v1/packages/cisco-ccna')->assertOk()
            ->assertJsonPath('data.title', 'Cisco CCNA')
            ->assertJsonPath('data.certificate', 'Cisco CCNA 200-301')
            ->assertJsonCount(7, 'data.overview.learn')
            ->assertJsonCount(2, 'data.schedules')
            ->assertJsonPath('data.schedules.0.dateLabel', '17 November 2026')
            ->assertJsonCount(3, 'data.related')
            ->assertJsonPath('data.related.0.slug', 'mikrotik-mtcna');

        $this->getJson('/api/v1/packages/tidak-ada')->assertNotFound();
    }

    public function test_schedules_list_and_detail(): void
    {
        $this->getJson('/api/v1/schedules')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.id', 'CCNA-2611')
            ->assertJsonPath('data.0.batchTitle', 'Cisco CCNA - Batch November')
            ->assertJsonPath('data.0.shortDate', '17 Nov 2026')
            ->assertJsonPath('data.0.time', '08.30 - 16.30 WIB')
            ->assertJsonPath('data.0.remaining', 9)
            ->assertJsonPath('data.1.status', 'wn');

        $this->getJson('/api/v1/schedules/LNX-2701')->assertOk()
            ->assertJsonPath('data.status', 'no')->assertJsonPath('data.statusLabel', 'Penuh')
            ->assertJsonCount(3, 'related')
            ->assertJsonPath('package.slug', 'linux-administration');

        $this->getJson('/api/v1/schedules?kategori=Networking')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/schedules/XXX')->assertNotFound();
    }

    public function test_posts_by_type_and_detail(): void
    {
        $this->getJson('/api/v1/posts?type=tutorial')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.slug', 'konfigurasi-eigrp')
            ->assertJsonPath('data.0.dateLabel', '10 Nov 2026')
            ->assertJsonMissingPath('data.0.body');
        $this->getJson('/api/v1/posts?type=berita')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/posts?type=dokumentasi&urut=az')->assertOk()->assertJsonCount(6, 'data');

        $this->getJson('/api/v1/posts/konfigurasi-eigrp')->assertOk()
            ->assertJsonPath('data.category', 'Tutorial')
            ->assertJsonPath('data.categorySlug', 'tutorial')
            ->assertJsonCount(3, 'data.related')
            ->assertJsonFragment(['author' => 'Tim Instruktur PPTI']);
        $this->assertStringContainsString('<h2>Topologi Lab</h2>', $this->getJson('/api/v1/posts/konfigurasi-eigrp')->json('data.body'));
    }

    public function test_content_endpoints(): void
    {
        $this->getJson('/api/v1/partners')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/v1/partners?official=1')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.name', 'Cisco');
        $this->getJson('/api/v1/testimonials')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.role', 'Automation Technician • PT. Batam Industrial Automation • Batam');
        $this->getJson('/api/v1/galleries?limit=4')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/categories?home=1')->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('data.0.icon', 'cpu');
        $this->getJson('/api/v1/pages/faq')->assertOk()->assertJsonPath('data.title', 'FAQ');
        $this->getJson('/api/v1/pages/nope')->assertNotFound();
        $this->getJson('/api/v1/menus')->assertOk()->assertJsonCount(6, 'header')->assertJsonCount(3, 'footer')
            ->assertJsonPath('footer.0.title', 'Layanan');
        $this->getJson('/api/v1/settings?key=site')->assertOk()->assertJsonPath('value.shortName', 'VMES Training Center');
    }

    public function test_search(): void
    {
        $this->getJson('/api/v1/search?q=cisco')->assertOk()
            ->assertJsonPath('data.total', 8)
            ->assertJsonCount(1, 'data.courses')
            ->assertJsonCount(3, 'data.schedules')
            ->assertJsonCount(4, 'data.posts');
        $this->getJson('/api/v1/search?q=')->assertOk()->assertJsonPath('data.total', 0);
    }
}
