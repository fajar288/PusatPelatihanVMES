<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanyProfileSettings;
use App\Filament\Pages\HomepageSettings;
use App\Filament\Pages\PostCategorySettings;
use App\Filament\Resources\SiteSettings\Pages\CreateSiteSetting;
use App\Filament\Resources\SiteSettings\Pages\EditSiteSetting;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Partner;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CmsSettings;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssertsJsonColumns;
use Tests\TestCase;

/**
 * Editor terstruktur pengaturan CMS (Beranda, Profil perusahaan, Kategori artikel): data seed terbaca, tersimpan ulang
 * identik (round-trip), validasi ikon & jumlah item, slide/mitra resmi, revalidasi frontend, audit, dan hak akses.
 */
class CmsSettingsEditorTest extends TestCase
{
    use AssertsJsonColumns, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $settingsSeed;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsSeed = $this->seedJson('settings');
        foreach ($this->settingsSeed as $key => $value) {
            SiteSetting::set($key, $value, 'site');
        }
        foreach ($this->seedJson('banners') as $row) {
            Banner::query()->create($row);
        }
        foreach ($this->seedJson('partners') as $row) {
            Partner::query()->create($row);
        }
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        config(['services.frontend.url' => 'http://frontend.test', 'services.frontend.revalidate_secret' => 'rahasia-uji']);
        Http::fake(['frontend.test/*' => Http::response(['ok' => true])]);
    }

    /** @return array<array-key, mixed> */
    private function seedJson(string $name): array
    {
        return json_decode((string) file_get_contents(database_path("seeders/data/$name.json")), true);
    }

    /** @return array<string, mixed> */
    private function stored(string $key): array
    {
        return SiteSetting::query()->where('key', $key)->value('value');
    }

    public function test_normalize_keeps_seed_data_unchanged(): void
    {
        foreach (array_keys(CmsSettings::SHAPES) as $key) {
            $this->assertSame($this->settingsSeed[$key], CmsSettings::normalize($key, $this->settingsSeed[$key]), "bentuk $key berubah");
        }
    }

    public function test_round_trip_seed_is_read_and_saved_back_identically(): void
    {
        $this->actingAs($this->admin);
        $banners = Banner::query()->orderBy('id')->get()->toArray();
        $partners = Partner::query()->orderBy('id')->get()->toArray();

        $home = Livewire::test(HomepageSettings::class)
            ->assertSchemaStateSet([
                'hero.pill' => $this->settingsSeed['hero']['pill'],
                'hero.glassStat.title' => '12.000+ Lulusan',
                'about.titleEm' => $this->settingsSeed['about']['titleEm'],
                'cta.title' => $this->settingsSeed['cta']['title'],
            ]);
        $this->assertCount(5, $home->get('data.stats'));
        $this->assertCount(4, $home->get('data.heroMedia.slides'));
        $this->assertSame(['Cisco', 'MikroTik', 'Pearson VUE', 'Certiport'], array_column(array_values($home->get('data.heroMedia.officialPartners')), 'name'));
        $home->call('save')->assertHasNoFormErrors()->assertNotified('Tidak ada perubahan untuk disimpan.');

        Livewire::test(CompanyProfileSettings::class)
            ->assertSchemaStateSet(['company.vision' => $this->settingsSeed['company']['vision']])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(PostCategorySettings::class)->call('save')->assertHasNoFormErrors();

        foreach (array_keys(CmsSettings::SHAPES) as $key) {
            $this->assertJsonColumn($this->settingsSeed[$key], $this->stored($key), "round-trip $key tidak identik");
        }
        $this->assertSame($banners, Banner::query()->orderBy('id')->get()->toArray());
        $this->assertSame($partners, Partner::query()->orderBy('id')->get()->toArray());
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'settings.%')->count());
        Http::assertNothingSent();
    }

    public function test_saving_homepage_keeps_structure_updates_api_revalidates_and_audits(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(HomepageSettings::class);
        $stats = array_values($page->get('data.stats'));
        $stats[0]['n'] = '15000';
        $page->set('data.hero.pill', 'Pusat Pelatihan Baru')
            ->set('data.stats', $stats)
            ->set('data.about.paragraphs', [['text' => 'Paragraf satu.'], ['text' => 'Paragraf dua.']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Tersimpan. Situs diperbarui dalam beberapa detik.');

        $hero = $this->stored('hero');
        $this->assertJsonKeys(array_keys($this->settingsSeed['hero']), $hero);
        $this->assertSame('Pusat Pelatihan Baru', $hero['pill']);
        $this->assertJsonColumn($this->settingsSeed['hero']['features'], $hero['features']);
        $this->assertSame(15000, $this->stored('stats')[0]['n']);
        $this->assertJsonKeys(['icon', 'n', 'suffix', 'label'], $this->stored('stats')[0]);
        $this->assertSame(['Paragraf satu.', 'Paragraf dua.'], $this->stored('about')['paragraphs']);
        $this->assertJsonColumn($this->settingsSeed['why'], $this->stored('why'));

        $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonPath('data.hero.pill', 'Pusat Pelatihan Baru')
            ->assertJsonPath('data.stats.0.n', 15000)
            ->assertJsonCount(4, 'data.hero.slides')
            ->assertJsonPath('data.about.paragraphs.1', 'Paragraf dua.');

        Http::assertSent(fn ($request) => $request->url() === 'http://frontend.test/api/revalidate'
            && in_array('home', $request['tags'], true) && in_array('settings', $request['tags'], true));

        $audit = AuditLog::query()->where('action', 'settings.hero_updated')->firstOrFail();
        $this->assertSame($this->settingsSeed['hero']['pill'], $audit->before['value']['pill']);
        $this->assertSame('Pusat Pelatihan Baru', $audit->after['value']['pill']);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertTrue(AuditLog::query()->where('action', 'settings.stats_updated')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'settings.why_updated')->exists());
    }

    public function test_icons_and_item_limits_are_validated(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(HomepageSettings::class);
        $features = array_values($page->get('data.hero.features'));
        $features[0]['icon'] = 'ikon-bebas';
        $page->set('data.hero.features', $features)->call('save')->assertHasFormErrors();
        $this->assertSame('users', $this->stored('hero')['features'][0]['icon']);

        $stats = array_values(Livewire::test(HomepageSettings::class)->get('data.stats'));
        $stats[] = ['icon' => 'star', 'n' => 1, 'suffix' => '', 'label' => 'Ekstra'];
        Livewire::test(HomepageSettings::class)->set('data.stats', $stats)->call('save')->assertHasFormErrors(['stats']);
        $this->assertCount(5, $this->stored('stats'));
    }

    public function test_slides_and_official_partners_sync_with_banner_and_partner_tables(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(HomepageSettings::class);
        $slides = array_values($page->get('data.heroMedia.slides'));
        $this->assertEmpty($slides[0]['image_upload']);
        $this->assertStringStartsWith('https://', $slides[0]['image_url']);

        // Urutan dibalik, slide terakhir dihapus, satu URL baru ditambah.
        $kept = array_reverse(array_slice($slides, 0, 3));
        $kept[] = ['id' => null, 'image_upload' => null, 'image_url' => 'https://images.example.com/lab.jpg', 'alt' => 'Lab baru'];
        $partners = array_values($page->get('data.heroMedia.officialPartners'));
        array_pop($partners); // Certiport dilepas dari Partner Resmi
        $partners[0]['logo_url'] = 'https://cdn.example.com/cisco.svg';

        $page->set('data.heroMedia.slides', $kept)->set('data.heroMedia.officialPartners', $partners)
            ->call('save')->assertHasNoFormErrors();

        $banners = Banner::query()->where('position', 'hero')->orderBy('sort_order')->get();
        $this->assertSame([$slides[2]['id'], $slides[1]['id'], $slides[0]['id']], $banners->take(3)->pluck('id')->all());
        $this->assertSame('https://images.example.com/lab.jpg', $banners[3]->image);
        $this->assertSame([1, 2, 3, 4], $banners->pluck('sort_order')->all());
        $this->assertNull(Banner::query()->find($slides[3]['id']));

        $certiport = Partner::query()->where('slug', 'certiport')->firstOrFail();
        $this->assertFalse($certiport->is_official);
        $cisco = Partner::query()->where('slug', 'cisco')->firstOrFail();
        $this->assertSame('https://cdn.example.com/cisco.svg', $cisco->logo);
        $this->assertNull($cisco->width); // logo baru tampil tanpa ukuran tetap
        $this->assertSame(156, Partner::query()->where('slug', 'mikrotik')->value('height'));

        $this->getJson('/api/v1/home')->assertJsonCount(3, 'data.hero.officialPartners')->assertJsonPath('data.hero.slides.3.alt', 'Lab baru');
        $audit = AuditLog::query()->where('action', 'settings.hero_media_updated')->firstOrFail();
        $this->assertCount(4, $audit->before['officialPartners']);
        $this->assertCount(3, $audit->after['officialPartners']);

        // URL tidak valid ditolak.
        $bad = array_values(Livewire::test(HomepageSettings::class)->get('data.heroMedia.slides'));
        $bad[0]['image_url'] = 'javascript:alert(1)';
        Livewire::test(HomepageSettings::class)->set('data.heroMedia.slides', $bad)->call('save')->assertHasFormErrors();
    }

    public function test_company_profile_saves_with_same_structure(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(CompanyProfileSettings::class);
        $services = array_values($page->get('data.company.services'));
        $services[0]['href'] = 'bukan-tautan';
        $page->set('data.company.services', $services)->call('save')->assertHasFormErrors();

        $services[0]['href'] = '/kontak';
        Livewire::test(CompanyProfileSettings::class)
            ->set('data.company.services', $services)
            ->set('data.company.missions', [['text' => 'Misi baru satu'], ['text' => 'Misi baru dua']])
            ->call('save')->assertHasNoFormErrors();

        $company = $this->stored('company');
        $this->assertJsonKeys(array_keys($this->settingsSeed['company']), $company);
        $this->assertSame('/kontak', $company['services'][0]['href']);
        $this->assertSame(['Misi baru satu', 'Misi baru dua'], $company['missions']);
        $this->assertJsonColumn($this->settingsSeed['company']['trainers'], $company['trainers']);
        $this->getJson('/api/v1/settings?key=company')->assertJsonPath('value.missions.1', 'Misi baru dua');
        $this->assertTrue(AuditLog::query()->where('action', 'settings.company_updated')->exists());
    }

    public function test_post_categories_only_title_and_description_change(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(PostCategorySettings::class);
        $cats = array_values($page->get('data.post_categories'));
        $cats[1]['title'] = 'Berita Terbaru';
        $cats[1]['slug'] = 'diubah'; // tidak boleh mengubah alamat halaman
        $cats[1]['category'] = 'Lain';
        unset($cats[3]);
        $page->set('data.post_categories', $cats)->call('save')->assertHasNoFormErrors();

        $stored = $this->stored('post_categories');
        $this->assertSame(array_column($this->settingsSeed['post_categories'], 'slug'), array_column($stored, 'slug'));
        $this->assertSame('Berita', $stored[1]['category']);
        $this->assertSame('Berita Terbaru', $stored[1]['title']);
        $this->assertJsonColumn($this->settingsSeed['post_categories'][3], $stored[3]);
    }

    public function test_json_editor_is_read_only_for_structured_keys_except_super_admin(): void
    {
        $this->seed(RolesSeeder::class);
        $hero = SiteSetting::query()->where('key', 'hero')->firstOrFail();
        $marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);

        $this->actingAs($marketing);
        $this->get("/admin/site-settings/{$hero->id}/edit")->assertOk()->assertSee('Buka editor Beranda')->assertSee('Hanya-baca');
        Livewire::test(EditSiteSetting::class, ['record' => $hero->id])
            ->assertActionVisible('openEditor')
            ->assertFormFieldIsDisabled('value')
            ->call('save')->assertForbidden();
        $this->assertJsonColumn($this->settingsSeed['hero'], $this->stored('hero'));
        Livewire::test(CreateSiteSetting::class)
            ->fillForm(['key' => 'company', 'group' => 'site', 'value' => '{"vision":"x"}'])
            ->call('create')->assertHasFormErrors(['key' => 'not_in']);

        // Super admin: jalan darurat JSON tetap ada dan tercatat di audit.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $value = $this->settingsSeed['hero'];
        $value['pill'] = 'Darurat';
        Livewire::test(EditSiteSetting::class, ['record' => $hero->id])
            ->assertFormFieldIsEnabled('value')
            ->fillForm(['value' => json_encode($value)])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Darurat', $this->stored('hero')['pill']);
        $audit = AuditLog::query()->where('action', 'settings.hero_updated')->firstOrFail();
        $this->assertSame('json', $audit->after['mode']);
    }

    public function test_access_follows_cms_permission(): void
    {
        $this->seed(RolesSeeder::class);
        foreach ([User::ROLE_ADMINISTRATOR, User::ROLE_MANAGEMENT, User::ROLE_MARKETING] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertTrue(HomepageSettings::canAccess(), $role);
            $this->assertTrue(SiteSettingResource::canViewAny(), $role);
            $this->get('/admin/konten-beranda')->assertOk()->assertSee('Slide foto');
            $this->get('/admin/profil-perusahaan')->assertOk()->assertSee('Visi');
            $this->get('/admin/kategori-artikel')->assertOk()->assertSee('Halaman kategori');
            $this->app['auth']->forgetGuards();
        }
        foreach ([User::ROLE_FINANCE, User::ROLE_TRAINING_COORDINATOR] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertFalse(HomepageSettings::canAccess(), $role);
            $this->assertFalse(CompanyProfileSettings::canAccess(), $role);
            $this->get('/admin/konten-beranda')->assertForbidden();
            $this->get('/admin/kategori-artikel')->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_editor_urls_point_to_the_right_page(): void
    {
        $this->assertStringEndsWith('/admin/konten-beranda?tab=stats', (string) CmsSettings::editorUrl('stats'));
        $this->assertStringEndsWith('/admin/profil-perusahaan', (string) CmsSettings::editorUrl('company'));
        $this->assertStringEndsWith('/admin/identitas-kontak', (string) CmsSettings::editorUrl('site'));
        $this->assertNull(CmsSettings::editorUrl('finance'));
    }
}
