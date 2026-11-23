<?php

namespace App\Filament\Support;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Support\CmsSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Dasar halaman editor terstruktur untuk pengaturan CMS (pola sama dengan Identitas & kontak): state form per kunci
 * site_settings, disimpan lewat CmsSettings (bentuk JSON tetap, audit, revalidasi frontend lewat CmsObserver).
 *
 * @property-read Schema $form
 */
abstract class CmsSettingsPage extends Page
{
    protected string $view = 'filament.pages.cms-settings';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::LandingPage;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @return list<string> kunci site_settings yang disunting halaman ini */
    abstract protected static function settingKeys(): array;

    /** Hak akses sama dengan menu Pengaturan situs (izin cms.manage). */
    public static function canAccess(): bool
    {
        return SiteSettingResource::canViewAny();
    }

    public function mount(): void
    {
        $this->form->fill($this->loadState());
    }

    /** @return array<string, mixed> */
    protected function loadState(): array
    {
        $state = [];
        foreach (static::settingKeys() as $key) {
            $state[$key] = CmsSettings::current($key);
        }

        return $state;
    }

    /**
     * Simpan data tambahan di luar site_settings (mis. slide hero). Kembalikan true bila ada perubahan.
     *
     * @param  array<string, mixed>  $state
     */
    protected function saveExtra(array $state): bool
    {
        return false;
    }

    /** Teks di samping tombol Simpan. */
    public function getSaveHint(): string
    {
        return 'Perubahan tampil di situs dalam beberapa detik setelah disimpan.';
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $changed = false;
        foreach (static::settingKeys() as $key) {
            $changed = CmsSettings::save($key, $state[$key] ?? []) || $changed;
        }
        $changed = $this->saveExtra($state) || $changed;

        $this->form->fill($this->loadState());
        Notification::make()
            ->title($changed ? 'Tersimpan. Situs diperbarui dalam beberapa detik.' : 'Tidak ada perubahan untuk disimpan.')
            ->{$changed ? 'success' : 'info'}()
            ->send();
    }
}
