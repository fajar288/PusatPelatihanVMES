<?php

namespace App\Filament\Resources\SiteSettings\Pages;

use App\Filament\Pages\SiteIdentitySettings;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Support\SiteIdentity;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSiteSetting extends EditRecord
{
    protected static string $resource = SiteSettingResource::class;

    /** Kunci `site` disunting lewat editor terstruktur (Identitas & kontak) agar format JSON tidak rusak. */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        if ($this->getRecord()->key === SiteIdentity::KEY) {
            $this->redirect(SiteIdentitySettings::getUrl());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Pengaturan `site` dipakai seluruh situs: tidak boleh dihapus.
            DeleteAction::make()->visible(fn () => $this->getRecord()->key !== SiteIdentity::KEY),
        ];
    }
}
