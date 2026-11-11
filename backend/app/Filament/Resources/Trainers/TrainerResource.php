<?php

namespace App\Filament\Resources\Trainers;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Trainers\Pages\CreateTrainer;
use App\Filament\Resources\Trainers\Pages\EditTrainer;
use App\Filament\Resources\Trainers\Pages\ListTrainers;
use App\Filament\Resources\Trainers\Schemas\TrainerForm;
use App\Filament\Resources\Trainers\Tables\TrainersTable;
use App\Models\Trainer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainerResource extends Resource
{
    protected static ?string $model = Trainer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsProgram;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Trainer';

    protected static ?string $modelLabel = 'Trainer';

    protected static ?string $pluralModelLabel = 'Trainer';

    public static function form(Schema $schema): Schema
    {
        return TrainerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainers::route('/'),
            'create' => CreateTrainer::route('/create'),
            'edit' => EditTrainer::route('/{record}/edit'),
        ];
    }
}
