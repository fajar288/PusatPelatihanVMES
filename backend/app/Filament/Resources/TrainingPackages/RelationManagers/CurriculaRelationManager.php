<?php

namespace App\Filament\Resources\TrainingPackages\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Kurikulum per paket: unit/modul, topik, durasi, metode, hasil belajar (PRD §5.3). */
class CurriculaRelationManager extends RelationManager
{
    protected static string $relationship = 'curricula';

    protected static ?string $title = 'Kurikulum';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('sort_order')->label('Urutan')->numeric()->default(fn () => ($this->getOwnerRecord()->curricula()->max('sort_order') ?? 0) + 1),
            TextInput::make('title')->label('Unit / modul')->required()->maxLength(255),
            TagsInput::make('topics')->label('Topik')->placeholder('Tambah topik, Enter')->columnSpanFull(),
            TextInput::make('duration_hours')->label('Durasi (jam)')->numeric()->minValue(0),
            TextInput::make('method')->label('Metode')->maxLength(60)->placeholder('Teori / Praktik / Studi kasus'),
            Textarea::make('outcomes')->label('Hasil belajar')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('title')->label('Unit / modul')->searchable(),
                TextColumn::make('topics')->label('Topik')->badge()->limitList(4),
                TextColumn::make('duration_hours')->label('Jam'),
                TextColumn::make('method')->label('Metode'),
            ])
            ->headerActions([CreateAction::make()->label('Tambah modul')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
