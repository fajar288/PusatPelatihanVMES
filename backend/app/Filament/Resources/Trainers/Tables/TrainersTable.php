<?php

namespace App\Filament\Resources\Trainers\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TrainersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable()->description(fn ($record) => $record->title),
                TextColumn::make('expertise')->label('Keahlian')->badge()->limitList(3),
                TextColumn::make('email')->searchable(),
                IconColumn::make('is_assessor')->label('Assessor')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('schedules_count')->label('Kelas')->counts('schedules'),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Aktif'), TernaryFilter::make('is_assessor')->label('Assessor')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
