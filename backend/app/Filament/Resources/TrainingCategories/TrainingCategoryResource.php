<?php

namespace App\Filament\Resources\TrainingCategories;

use App\Filament\NavigationGroup;
use App\Filament\Resources\TrainingCategories\Pages\CreateTrainingCategory;
use App\Filament\Resources\TrainingCategories\Pages\EditTrainingCategory;
use App\Filament\Resources\TrainingCategories\Pages\ListTrainingCategories;
use App\Filament\Resources\TrainingCategories\Schemas\TrainingCategoryForm;
use App\Filament\Resources\TrainingCategories\Tables\TrainingCategoriesTable;
use App\Models\TrainingCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TrainingCategoryResource extends Resource
{
    protected static ?string $model = TrainingCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsProgram;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Kategori training';

    protected static ?string $modelLabel = 'Kategori training';

    protected static ?string $pluralModelLabel = 'Kategori training';

    public static function form(Schema $schema): Schema
    {
        return TrainingCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrainingCategoriesTable::configure($table);
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
            'index' => ListTrainingCategories::route('/'),
            'create' => CreateTrainingCategory::route('/create'),
            'edit' => EditTrainingCategory::route('/{record}/edit'),
        ];
    }
}
