<?php

namespace App\Filament\Resources\SiteSettings\Schemas;

use App\Models\SiteSetting;
use App\Support\CmsSettings;
use App\Support\SiteIdentity;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class SiteSettingForm
{
    /**
     * JSON mentah hanya boleh disunting untuk kunci tanpa editor terstruktur. Kunci yang punya editor hanya-baca,
     * kecuali untuk super admin (jalan darurat bila editor bermasalah).
     */
    public static function jsonLocked(?SiteSetting $record): bool
    {
        return $record !== null && CmsSettings::hasEditor($record->key) && ! auth()->user()?->isSuperAdmin();
    }

    public static function configure(Schema $schema): Schema
    {
        $structured = array_merge([SiteIdentity::KEY], array_keys(CmsSettings::SHAPES));

        return $schema
            ->components([
                Section::make('Pengaturan situs')->columns(2)->components([
                    TextInput::make('key')->label('Kunci')->required()->maxLength(80)->unique('site_settings', 'key', ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Kunci yang punya editor sendiri (site, hero, stats, about, why, cta, company, post_categories) tidak dibuat di sini.')
                        ->rule(Rule::notIn($structured), fn (string $operation) => $operation === 'create')
                        ->validationMessages(['not_in' => 'Kunci ini disunting lewat editor terstruktur (menu Beranda, Profil perusahaan, Kategori artikel, atau Identitas & kontak).']),
                    TextInput::make('group')->label('Grup')->maxLength(40)->default('site')
                        ->disabled(fn (?SiteSetting $record) => self::jsonLocked($record)),
                    Textarea::make('value')
                        ->label('Nilai (JSON)')
                        ->rows(24)
                        ->required()
                        ->disabled(fn (?SiteSetting $record) => self::jsonLocked($record))
                        ->formatStateUsing(fn ($state) => is_string($state) ? $state : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->dehydrateStateUsing(fn ($state) => json_decode((string) $state, true, 512, JSON_THROW_ON_ERROR))
                        ->rule('json')
                        ->helperText(fn (?SiteSetting $record) => match (true) {
                            $record === null || ! CmsSettings::hasEditor($record->key) => 'Struktur harus sama dengan yang dibaca website.',
                            self::jsonLocked($record) => 'Hanya-baca. Sunting lewat tombol "Buka editor" di atas agar format tidak rusak.',
                            default => 'Mode darurat super admin: struktur harus tetap sama dengan yang dibaca website. Gunakan editor terstruktur bila memungkinkan.',
                        })
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
