<?php

namespace App\Filament\Resources\TrainingLogbooks\Pages;

use App\Filament\Resources\TrainingLogbooks\TrainingLogbookResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingLogbooks extends ListRecords
{
    protected static string $resource = TrainingLogbookResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
