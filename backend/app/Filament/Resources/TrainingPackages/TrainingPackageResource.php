<?php

namespace App\Filament\Resources\TrainingPackages;

use App\Filament\Resources\TrainingPackages\Pages\CreateTrainingPackage;
use App\Filament\Resources\TrainingPackages\Pages\EditTrainingPackage;
use App\Filament\Resources\TrainingPackages\Pages\ListTrainingPackages;
use App\Filament\Resources\TrainingPackages\RelationManagers\CurriculaRelationManager;
use App\Filament\Resources\TrainingPackages\Schemas\TrainingPackageForm;
use App\Filament\Resources\TrainingPackages\Tables\TrainingPackagesTable;
use App\Models\TrainingPackage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TrainingPackageResource extends Resource
{
    protected static ?string $model = TrainingPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return TrainingPackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingPackagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CurriculaRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingPackages::route('/'),
            'create' => CreateTrainingPackage::route('/create'),
            'edit' => EditTrainingPackage::route('/{record}/edit'),
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
