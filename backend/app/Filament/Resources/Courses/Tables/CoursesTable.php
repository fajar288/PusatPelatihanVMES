<?php

namespace App\Filament\Resources\Courses\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->label('Course')->searchable()->sortable()->description(fn ($record) => $record->slug),
                TextColumn::make('package.title')->label('Program')->searchable()->wrap(),
                TextColumn::make('schedule.code')->label('Khusus kelas')->placeholder('Semua kelas'),
                TextColumn::make('modules_count')->label('Modul')->counts('modules'),
                TextColumn::make('lessons_count')->label('Lesson')->counts('lessons'),
                IconColumn::make('is_published')->label('Terbit')->boolean(),
                TextColumn::make('access_days_after')->label('Akses pasca-kelas')->formatStateUsing(fn ($state) => $state === null ? 'Selamanya' : "{$state} hari"),
            ])
            ->filters([
                SelectFilter::make('package_id')->label('Program')->relationship('package', 'title')->searchable()->preload(),
                TernaryFilter::make('is_published')->label('Terbit'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
