<?php

namespace App\Filament\Resources\SiteSettings\Pages;

use App\Filament\Pages\SiteIdentitySettings;
use App\Filament\Resources\SiteSettings\Schemas\SiteSettingForm;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Models\AuditLog;
use App\Support\CmsSettings;
use App\Support\JsonValue;
use App\Support\SiteIdentity;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSiteSetting extends EditRecord
{
    protected static string $resource = SiteSettingResource::class;

    /** @var mixed nilai sebelum disimpan, untuk audit */
    private mixed $valueBefore = null;

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
        $key = (string) $this->getRecord()->key;

        return [
            Action::make('openEditor')
                ->label('Buka editor '.CmsSettings::editorLabel($key))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn () => CmsSettings::editorUrl($key))
                ->visible(CmsSettings::hasEditor($key)),
            // Pengaturan yang dibaca website (punya editor terstruktur) tidak boleh dihapus.
            DeleteAction::make()->visible(! CmsSettings::hasEditor($key)),
        ];
    }

    protected function getFormActions(): array
    {
        return SiteSettingForm::jsonLocked($this->getRecord()) ? [$this->getCancelFormAction()] : parent::getFormActions();
    }

    /** Penjaga di server: field yang dinonaktifkan di form tidak cukup untuk mencegah penyimpanan. */
    protected function beforeValidate(): void
    {
        abort_if(SiteSettingForm::jsonLocked($this->getRecord()), 403);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->valueBefore = $this->getRecord()->value;

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if (! JsonValue::same($record->value, $this->valueBefore)) {
            AuditLog::record("settings.{$record->key}_updated", null, ['value' => $this->valueBefore, 'mode' => 'json'], ['value' => $record->value, 'mode' => 'json']);
        }
    }
}
