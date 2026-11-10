<?php

namespace App\Filament\Resources\Galleries\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GalleryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Foto galeri')->columns(2)->components([
                    TextInput::make('title')->label('Judul / alt')->required()->maxLength(255),
                    TextInput::make('category')->label('Kategori')->maxLength(60),
                    Fields::image('image', 'galleries')->label('Foto')->required()->columnSpanFull(),
                    TextInput::make('caption')->label('Keterangan')->maxLength(255),
                    DatePicker::make('event_date')->label('Tanggal kegiatan')->native(false),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
