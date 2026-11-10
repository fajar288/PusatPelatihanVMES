<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Course')->columns(2)->components([
                Fields::title('title')->label('Judul course'),
                Fields::slug('courses'),
                Select::make('package_id')->label('Program (paket)')->relationship('package', 'title')->searchable()->preload()->required()->live()
                    ->helperText('Course berlaku untuk semua kelas dari program ini, kecuali dikhususkan ke satu kelas di bawah.'),
                Select::make('schedule_id')->label('Khusus kelas (opsional)')->searchable()->preload()
                    ->relationship('schedule', 'code', fn ($query, Get $get) => $query->when($get('package_id'), fn ($q, $id) => $q->where('package_id', $id))->orderByDesc('start_date'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->code} — ".($record->title ?? $record->package?->title).' ('.$record->start_date->format('d/m/Y').')')
                    ->helperText('Kosongkan agar berlaku untuk semua kelas program.'),
                Textarea::make('description')->label('Deskripsi')->rows(3)->columnSpanFull(),
                Fields::image('thumbnail', 'lms/courses', 1200)->label('Thumbnail'),
            ]),
            Section::make('Akses & publikasi')->columns(4)->components([
                Toggle::make('is_published')->label('Dipublikasikan')->inline(false),
                Toggle::make('available_before_class')->label('Bisa dibuka sebelum kelas mulai')->inline(false),
                TextInput::make('access_days_after')->label('Akses setelah kelas (hari)')->numeric()->minValue(0)->helperText('Kosong = selamanya.'),
                TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
            ]),
        ]);
    }
}
