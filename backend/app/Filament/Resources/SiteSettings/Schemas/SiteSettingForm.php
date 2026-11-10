<?php

namespace App\Filament\Resources\SiteSettings\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pengaturan situs')->components([
                    TextInput::make('key')->label('Kunci')->required()->maxLength(80)->unique('site_settings', 'key', ignoreRecord: true)
                        ->helperText('site, hero, stats, about, why, cta, company, post_categories'),
                    TextInput::make('group')->label('Grup')->maxLength(40)->default('site'),
                    Textarea::make('value')
                        ->label('Nilai (JSON)')
                        ->rows(24)
                        ->required()
                        ->formatStateUsing(fn ($state) => is_string($state) ? $state : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->dehydrateStateUsing(fn ($state) => json_decode((string) $state, true, 512, JSON_THROW_ON_ERROR))
                        ->rule('json')
                        ->helperText('Struktur harus sama dengan yang dibaca frontend (lihat frontend/src/lib/cms-types.ts). Editor terstruktur menyusul.')
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
