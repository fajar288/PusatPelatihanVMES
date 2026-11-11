<?php

namespace App\Filament\Resources\AssessmentRubrics\Pages;

use App\Filament\Resources\AssessmentRubrics\AssessmentRubricResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentRubrics extends ListRecords
{
    protected static string $resource = AssessmentRubricResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
