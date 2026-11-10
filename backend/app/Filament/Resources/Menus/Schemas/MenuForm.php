<?php

namespace App\Filament\Resources\Menus\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item menu')->columns(2)->components([
                    Select::make('location')->label('Lokasi')
                        ->options(['header' => 'Header', 'footer_1' => 'Footer kolom 1', 'footer_2' => 'Footer kolom 2', 'footer_3' => 'Footer kolom 3'])
                        ->required()->native(false),
                    TextInput::make('group_title')->label('Judul kolom footer')->maxLength(255),
                    TextInput::make('label')->required()->maxLength(255),
                    TextInput::make('url')->label('URL')->required()->maxLength(255)->helperText('Path internal (/kursus), URL penuh, atau "wa" untuk tautan WhatsApp'),
                    Select::make('parent_id')->label('Induk')->relationship('parent', 'label')->searchable()->preload(),
                    Toggle::make('is_external')->label('Buka di tab baru'),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
