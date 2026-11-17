<?php

namespace App\Filament\Resources\SurveyTemplates\Pages;

use App\Filament\Resources\SurveyTemplates\SurveyTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSurveyTemplates extends ManageRecords
{
    protected static string $resource = SurveyTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah template')];
    }
}
