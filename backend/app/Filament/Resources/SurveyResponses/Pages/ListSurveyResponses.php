<?php

namespace App\Filament\Resources\SurveyResponses\Pages;

use App\Filament\Resources\SurveyResponses\SurveyResponseResource;
use App\Filament\Widgets\SurveyStats;
use App\Filament\Widgets\SurveySummaryTable;
use Filament\Resources\Pages\ListRecords;

class ListSurveyResponses extends ListRecords
{
    protected static string $resource = SurveyResponseResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SurveyStats::class, SurveySummaryTable::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
