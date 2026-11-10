<?php

namespace App\Filament\Resources\TrainingPackages\Pages;

use App\Filament\Resources\TrainingPackages\TrainingPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingPackages extends ListRecords
{
    protected static string $resource = TrainingPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
