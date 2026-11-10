<?php

namespace App\Filament\Resources\TrainingCategories\Pages;

use App\Filament\Resources\TrainingCategories\TrainingCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrainingCategory extends EditRecord
{
    protected static string $resource = TrainingCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
