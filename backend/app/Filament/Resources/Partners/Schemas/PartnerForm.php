<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mitra / Principal')->columns(2)->components([
                    Fields::title('name')->label('Nama'),
                    Fields::slug('partners'),
                    Select::make('type')
                        ->label('Jenis')
                        ->options(['client' => 'Client / perusahaan', 'principal' => 'Principal / vendor teknologi', 'certification' => 'Partner sertifikasi'])
                        ->default('client')->required()->native(false),
                    TextInput::make('website')->url()->maxLength(255),
                    Fields::image('logo', 'partners', 1200)->label('Logo')->columnSpanFull(),
                    Textarea::make('description')->label('Deskripsi')->rows(3)->columnSpanFull(),
                    Toggle::make('is_official')->label('Tampil di "Partner Resmi" (hero beranda)'),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
