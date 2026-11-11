<?php

namespace App\Filament\Resources\TrainingSchedules;

use App\Filament\NavigationGroup;
use App\Filament\Resources\TrainingSchedules\Pages\CreateTrainingSchedule;
use App\Filament\Resources\TrainingSchedules\Pages\EditTrainingSchedule;
use App\Filament\Resources\TrainingSchedules\Pages\ListTrainingSchedules;
use App\Filament\Resources\TrainingSchedules\RelationManagers\ChangesRelationManager;
use App\Filament\Resources\TrainingSchedules\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\TrainingSchedules\RelationManagers\SessionsRelationManager;
use App\Filament\Resources\TrainingSchedules\RelationManagers\TrainersRelationManager;
use App\Filament\Resources\TrainingSchedules\Schemas\TrainingScheduleForm;
use App\Filament\Resources\TrainingSchedules\Tables\TrainingSchedulesTable;
use App\Models\TrainingSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class TrainingScheduleResource extends Resource
{
    protected static ?string $model = TrainingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsProgram;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Jadwal & kelas';

    protected static ?string $modelLabel = 'Jadwal kelas';

    protected static ?string $pluralModelLabel = 'Jadwal & kelas';

    public static function form(Schema $schema): Schema
    {
        return TrainingScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingSchedulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TrainersRelationManager::class,
            SessionsRelationManager::class,
            EnrollmentsRelationManager::class,
            ChangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingSchedules::route('/'),
            'create' => CreateTrainingSchedule::route('/create'),
            'edit' => EditTrainingSchedule::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
