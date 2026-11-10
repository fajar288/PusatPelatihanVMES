<?php

namespace App\Filament\Resources\TrainingCategories\Pages;

use App\Filament\Resources\TrainingCategories\TrainingCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrainingCategory extends CreateRecord
{
    protected static string $resource = TrainingCategoryResource::class;
}
