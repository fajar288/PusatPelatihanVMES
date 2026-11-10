<?php

namespace App\Filament\Resources\TrainingSchedules\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Penugasan trainer/assessor ke kelas (class_trainers, PRD §5.7). */
class TrainersRelationManager extends RelationManager
{
    protected static string $relationship = 'trainers';

    protected static ?string $title = 'Trainer & assessor';

    private const ROLES = ['lead' => 'Trainer utama', 'assistant' => 'Asisten', 'assessor' => 'Assessor'];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')->label('Peran')->options(self::ROLES)->default('lead')->required()->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('title')->label('Jabatan'),
                TextColumn::make('role')->label('Peran')->badge()->formatStateUsing(fn ($state) => self::ROLES[$state] ?? $state),
                TextColumn::make('email'),
            ])
            ->headerActions([
                AttachAction::make()->label('Tugaskan trainer')->preloadRecordSelect()
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect(),
                        Select::make('role')->label('Peran')->options(self::ROLES)->default('lead')->required()->native(false),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah peran'),
                DetachAction::make()->label('Lepas'),
            ]);
    }
}
