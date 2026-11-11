<?php

namespace App\Filament\Resources\AssessmentRubrics\Pages;

use App\Filament\Resources\AssessmentRubrics\AssessmentRubricResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssessmentRubric extends EditRecord
{
    protected static string $resource = AssessmentRubricResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => $this->record->assessments()->doesntExist())];
    }
}
