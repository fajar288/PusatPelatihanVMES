<?php

namespace App\Filament\Resources\TrainingRequests\Pages;

use App\Filament\Resources\TrainingRequests\TrainingRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTrainingRequest extends ViewRecord
{
    protected static string $resource = TrainingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return TrainingRequestResource::workflowActions();
    }
}
