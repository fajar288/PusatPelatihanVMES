<?php

namespace App\Filament\Resources\TrainingSchedules\Pages;

use App\Filament\Resources\TrainingSchedules\TrainingScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrainingSchedules extends ListRecords
{
    protected static string $resource = TrainingScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
