<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteIdentitySettings;
use App\Filament\Resources\SiteSettings\Pages\CreateSiteSetting;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\SiteIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssertsJsonColumns;
use Tests\TestCase;

/** Editor terstruktur pengaturan `site` (identitas & kontak): validasi, struktur JSON tetap, API, revalidasi frontend. */
class SiteIdentitySettingsTest extends TestCase
{
    use AssertsJsonColumns, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $siteSeed;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteSeed = json_decode((string) file_get_contents(database_path('seeders/data/settings.json')), true)['site'];
        SiteSetting::set('site', $this->siteSeed, 'site');
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        config(['services.frontend.url' => 'http://frontend.test', 'services.frontend.revalidate_secret' => 'rahasia-uji']);
        Http::fake(['frontend.test/*' => Http::response(['ok' => true])]);
    }

    /** @return array<string, mixed> */
    private function validForm(array $override = []): array
    {
        return array_merge([
            'waNumber' => '6281234567890',
            'waDisplay' => '0812-3456-7890',
            'waDefaultText' => 'Halo, saya ingin bertanya tentang training',
            'address' => 'Jl. Uji No. 1, Batam',
            'mapsUrl' => 'https://maps.google.com/?q=Jl.+Uji+No.+1+Batam',
            'name' => 'PPTI - VMES Training Center',
            'shortName' => 'VMES Training Center',
            'company' => 'PT. PPTI - VMES Training Center',
            'tagline' => 'Tagline uji',
            'description' => 'Deskripsi uji',
            'footerDescription' => 'Footer uji',
            'youtubeProfileId' => 'IClaATbg--A',
            'socials' => [],
        ], $override);
    }

    public function test_page_shows_current_values_and_is_reachable_from_json_editor(): void
    {
        $this->actingAs($this->admin)->get('/admin/identitas-kontak')->assertOk()
            ->assertSee('Nomor WhatsApp')->assertSee('Tautan Google Maps')->assertSee('Media sosial');
        Livewire::test(SiteIdentitySettings::class)->assertSchemaStateSet(['waNumber' => '6285121251285', 'waDisplay' => '0851-2125-1285']);

        // Kunci `site` tidak lagi disunting sebagai JSON mentah.
        $row = SiteSetting::query()->where('key', 'site')->firstOrFail();
        $this->get("/admin/site-settings/{$row->id}/edit")->assertRedirect('/admin/identitas-kontak');
        Livewire::test(CreateSiteSetting::class)
            ->fillForm(['key' => 'site', 'group' => 'site', 'value' => '{"name":"x"}'])
            ->call('create')->assertHasFormErrors(['key' => 'not_in']);
    }

    public function test_whatsapp_number_is_validated(): void
    {
        $this->actingAs($this->admin);
        foreach (['085121251285', '+6285121251285', '62 851 2125 1285', '62851', '6285121251285999', 'abc'] as $bad) {
            Livewire::test(SiteIdentitySettings::class)
                ->fillForm($this->validForm(['waNumber' => $bad]))
                ->call('save')
                ->assertHasFormErrors(['waNumber' => 'regex']);
        }
        Livewire::test(SiteIdentitySettings::class)->fillForm($this->validForm(['mapsUrl' => 'bukan url', 'youtubeProfileId' => 'pendek']))
            ->call('save')->assertHasFormErrors(['mapsUrl', 'youtubeProfileId']);
        $this->assertSame('6285121251285', SiteSetting::get('site')['waNumber']);
    }

    public function test_save_keeps_json_structure_updates_api_and_revalidates_frontend(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SiteIdentitySettings::class)
            ->fillForm($this->validForm(['socials' => [
                'a' => ['icon' => 'ig', 'href' => 'https://instagram.com/ppti', 'label' => 'Instagram PPTI'],
                'b' => ['icon' => 'yt', 'href' => 'https://youtube.com/@ppti', 'label' => ''],
            ]]))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $stored = SiteSetting::query()->where('key', 'site')->value('value');
        // Kunci & urutan sama dengan tipe SiteSettings frontend; logo dan logoSize (tidak ada di form) dipertahankan.
        $this->assertJsonKeys(SiteIdentity::KEYS, $stored);
        $this->assertJsonKeys(array_keys($this->siteSeed), $stored);
        $this->assertSame($this->siteSeed['logo'], $stored['logo']);
        $this->assertSame(['w' => 1600, 'h' => 769], $stored['logoSize']);
        $this->assertSame('6281234567890', $stored['waNumber']);
        $this->assertJsonColumn([['icon' => 'ig', 'href' => 'https://instagram.com/ppti', 'label' => 'Instagram PPTI'], ['icon' => 'yt', 'href' => 'https://youtube.com/@ppti', 'label' => '']], $stored['socials']);
        foreach ($stored as $key => $value) {
            $this->assertSame(gettype($this->siteSeed[$key]), gettype($value), "tipe $key berubah");
        }

        // Endpoint yang dibaca frontend langsung mengembalikan nilai baru (cache API di-flush).
        $this->getJson('/api/v1/settings?key=site')->assertOk()
            ->assertJsonPath('value.waNumber', '6281234567890')
            ->assertJsonPath('value.waDisplay', '0812-3456-7890')
            ->assertJsonPath('value.address', 'Jl. Uji No. 1, Batam')
            ->assertJsonPath('value.logo', '/images/logo.jpeg');

        // Revalidasi tag "settings" lewat mekanisme yang sudah ada (CmsObserver -> CmsCache::flush -> webhook frontend).
        Http::assertSent(fn ($request) => $request->url() === 'http://frontend.test/api/revalidate'
            && $request->header('x-revalidate-secret')[0] === 'rahasia-uji'
            && in_array('settings', $request['tags'], true));

        $audit = AuditLog::query()->where('action', 'settings.site_updated')->firstOrFail();
        $this->assertSame('6285121251285', $audit->before['waNumber']);
        $this->assertSame('6281234567890', $audit->after['waNumber']);
        $this->assertArrayNotHasKey('logo', $audit->after);
    }

    public function test_normalize_keeps_types_and_drops_unknown_keys(): void
    {
        $out = SiteIdentity::normalize(['waNumber' => '62-851 2125 1285', 'rahasia' => 'x', 'socials' => [['icon' => 'fb', 'href' => ''], ['icon' => 'in', 'href' => ' https://linkedin.com/x ', 'label' => null]]], $this->siteSeed);
        $this->assertSame(SiteIdentity::KEYS, array_keys($out));
        $this->assertSame('6285121251285', $out['waNumber']);
        $this->assertSame([['icon' => 'in', 'href' => 'https://linkedin.com/x', 'label' => '']], $out['socials']);
        $this->assertArrayNotHasKey('rahasia', $out);
    }

    public function test_whatsapp_placeholder_migration_only_touches_the_seeded_paragraph(): void
    {
        $old = '<p>Hubungi admin kami di WhatsApp 0851-2125-1285 pada hari kerja pukul 08.00 sampai 17.00 WIB.</p>';
        $page = Page::query()->create(['slug' => 'cara-pendaftaran', 'title' => 'Cara Pendaftaran', 'status' => 'published', 'body' => "<p>Paragraf lain.</p>\n$old"]);
        $migration = require database_path('migrations/2026_11_17_130000_use_whatsapp_placeholder_in_pages.php');

        $migration->up();
        $this->assertSame("<p>Paragraf lain.</p>\n<p>Hubungi admin kami di WhatsApp {{whatsapp}} pada hari kerja pukul 08.00 sampai 17.00 WIB.</p>", $page->refresh()->body);
        $migration->up(); // idempoten
        $this->assertStringContainsString('{{whatsapp}}', $page->refresh()->body);
        $migration->down();
        $this->assertSame("<p>Paragraf lain.</p>\n$old", $page->refresh()->body);

        // Halaman yang paragrafnya sudah diedit admin tidak disentuh.
        $page->update(['body' => '<p>Hubungi kami kapan saja.</p>']);
        $migration->up();
        $this->assertSame('<p>Hubungi kami kapan saja.</p>', $page->refresh()->body);
    }
}
