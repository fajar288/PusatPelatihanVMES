<?php

namespace App\Filament\Resources\Testimonials\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TestimonialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Testimoni')->columns(2)->components([
                    TextInput::make('name')->label('Nama')->required()->maxLength(255),
                    TextInput::make('position')->label('Jabatan')->maxLength(255),
                    TextInput::make('company')->label('Perusahaan / instansi')->maxLength(255),
                    TextInput::make('city')->label('Kota')->maxLength(80),
                    TextInput::make('program')->label('Program yang diikuti')->maxLength(255),
                    Select::make('package_id')->label('Paket training')->relationship('package', 'title')->searchable()->preload(),
                    Textarea::make('content')->label('Isi testimoni')->required()->rows(5)->columnSpanFull(),
                    Fields::image('photo', 'testimonials', 600)->label('Foto')->avatar(),
                    Select::make('rating')->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1'])->default(5)->required()->native(false),
                    Toggle::make('is_approved')->label('Disetujui untuk tampil'),
                    Toggle::make('is_featured')->label('Testimoni utama'),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
