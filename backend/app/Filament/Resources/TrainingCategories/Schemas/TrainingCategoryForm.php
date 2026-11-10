<?php

namespace App\Filament\Resources\TrainingCategories\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TrainingCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kategori')->columns(2)->components([
                    Fields::title('name')->label('Nama'),
                    Fields::slug('training_categories'),
                    Select::make('parent_id')->label('Induk (area kompetensi)')->relationship('parent', 'name', fn ($query) => $query->whereNull('parent_id'))->searchable()->preload(),
                    Fields::icon(),
                    Textarea::make('description')->label('Deskripsi')->rows(2)->columnSpanFull(),
                    Toggle::make('show_on_home')->label('Tampil di beranda (area kompetensi)'),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    TextInput::make('sort_order')->label('Urutan')->numeric()->default(0),
                ]),
            ]);
    }
}
