<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use App\Filament\Resources\Lessons\LessonResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Modul dalam course; lesson-nya dikelola di resource Lesson (filter per modul). */
class ModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Modul';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('Judul modul')->required()->maxLength(255),
            TextInput::make('sort_order')->label('Urutan')->numeric()->default(fn () => ($this->getOwnerRecord()->modules()->max('sort_order') ?? 0) + 1),
            Textarea::make('description')->label('Deskripsi')->rows(2)->columnSpanFull(),
            Toggle::make('is_published')->label('Terbit')->default(true),
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
                TextColumn::make('title')->label('Modul')->searchable()->description(fn ($record) => $record->description),
                TextColumn::make('lessons_count')->label('Lesson')->counts('lessons'),
                IconColumn::make('is_published')->label('Terbit')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Tambah modul')])
            ->recordActions([
                Action::make('lessons')->label('Kelola lesson')->icon('heroicon-o-list-bullet')
                    ->url(fn ($record) => LessonResource::getUrl('index', ['tableFilters' => ['module_id' => ['value' => $record->id]]])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
