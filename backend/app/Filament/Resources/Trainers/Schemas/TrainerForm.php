<?php

namespace App\Filament\Resources\Trainers\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas')->columns(2)->components([
                Fields::title('name')->label('Nama'),
                Fields::slug('trainers'),
                TextInput::make('title')->label('Jabatan / spesialisasi')->maxLength(255)->placeholder('Lead Trainer Networking'),
                Select::make('user_id')->label('Akun login (portal trainer)')->relationship('user', 'name')->searchable()->preload()->helperText('Opsional; untuk akses portal /trainer.'),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->label('Telepon')->maxLength(30),
                Fields::image('photo', 'trainers', 800)->label('Foto')->avatar(),
            ]),
            Section::make('Kompetensi')->components([
                TagsInput::make('expertise')->label('Keahlian')->placeholder('Tambah keahlian, Enter'),
                TagsInput::make('certifications')->label('Sertifikasi')->placeholder('CCNP, MTCNA, …'),
                Textarea::make('education')->label('Pendidikan')->rows(2),
                Textarea::make('experience')->label('Pengalaman')->rows(3),
                Textarea::make('portfolio')->label('Portofolio / tautan')->rows(2),
                Textarea::make('bio')->label('Bio singkat (tampil di web)')->rows(3),
            ])->collapsible(),
            Section::make('Status')->columns(4)->components([
                Toggle::make('is_assessor')->label('Assessor')->inline(false),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Toggle::make('show_on_site')->label('Tampil di web')->default(true)->inline(false),
                TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
            ]),
        ]);
    }
}
