<?php

namespace App\Filament\Resources\TrainingCategories\Pages;

use App\Filament\Resources\TrainingCategories\TrainingCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingCategories extends ListRecords
{
    protected static string $resource = TrainingCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
