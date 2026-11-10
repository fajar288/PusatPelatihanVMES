<?php

namespace App\Filament\Resources\TrainingPackages\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainingPackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Paket training')->columns(2)->components([
                    Fields::title()->label('Nama paket'),
                    Fields::slug('training_packages'),
                    TextInput::make('code')->label('Kode paket')->maxLength(40)->unique('training_packages', 'code', ignoreRecord: true),
                    Select::make('category_id')->label('Kategori')->relationship('category', 'name')->searchable()->preload(),
                    TextInput::make('vendor')->label('Vendor / brand')->maxLength(255),
                    TextInput::make('level')->label('Level')->maxLength(40)->placeholder('Fundamental / Intermediate / Advanced'),
                    Textarea::make('excerpt')->label('Deskripsi singkat (kartu)')->rows(2)->columnSpanFull(),
                    Fields::image('image', 'packages')->label('Gambar')->columnSpanFull(),
                ]),
                Section::make('Materi')->components([
                    Repeater::make('overview_intro')->label('Paragraf pembuka')->simple(Textarea::make('p')->rows(3)->required())->addActionLabel('Tambah paragraf'),
                    Textarea::make('objectives')->label('Tujuan pelatihan')->rows(3),
                    TagsInput::make('competencies')->label('Kompetensi')->placeholder('Tambah kompetensi, Enter'),
                    TagsInput::make('learning_outcomes')->label('Yang akan dipelajari')->placeholder('Tambah poin, Enter'),
                    TagsInput::make('audience')->label('Untuk siapa')->placeholder('Tambah poin, Enter'),
                    TagsInput::make('requirements')->label('Persyaratan peserta')->placeholder('Tambah poin, Enter'),
                ])->collapsible(),
                Section::make('Durasi, metode & harga')->columns(3)->components([
                    TextInput::make('duration_days')->label('Durasi (hari)')->numeric()->minValue(1)->default(1)->required(),
                    TextInput::make('duration_hours')->label('Jam pelajaran (JP)')->numeric()->minValue(0),
                    Fields::mode()->label('Metode'),
                    TextInput::make('price')->label('Harga (Rp)')->numeric()->minValue(0)->prefix('Rp'),
                    TextInput::make('quota')->label('Kuota default')->numeric()->minValue(0),
                    TextInput::make('certificate')->label('Sertifikat')->maxLength(255),
                ]),
                Section::make('Publikasi')->columns(3)->components([
                    Fields::status(),
                    Select::make('availability')->label('Ketersediaan')->options(['available' => 'Tersedia', 'coming_soon' => 'Segera dibuka', 'closed' => 'Ditutup'])->default('available')->required()->native(false),
                    DateTimePicker::make('published_at')->label('Tanggal terbit')->seconds(false)->native(false),
                    Toggle::make('is_popular')->label('Tandai Populer'),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                    TextInput::make('seo_title')->maxLength(255),
                    Textarea::make('seo_description')->rows(2)->maxLength(320)->columnSpanFull(),
                ])->collapsible(),
            ]);
    }
}
