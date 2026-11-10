<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Banner')->columns(2)->components([
                    Select::make('position')->label('Posisi')->options(['hero' => 'Slider hero beranda'])->default('hero')->required()->native(false),
                    TextInput::make('title')->label('Judul (opsional)')->maxLength(255),
                    Fields::image('image', 'banners', 1920)->label('Gambar')->required()->columnSpanFull(),
                    TextInput::make('alt')->label('Teks alternatif')->maxLength(255),
                    TextInput::make('link')->label('Tautan (opsional)')->maxLength(255),
                    DateTimePicker::make('starts_at')->label('Mulai tayang')->seconds(false)->native(false),
                    DateTimePicker::make('ends_at')->label('Selesai tayang')->seconds(false)->native(false),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
