<?php

namespace App\Filament\Resources\AiUsageRecords\Pages;

use App\Filament\Resources\AiUsageRecords\AiUsageRecordResource;
use App\Filament\Widgets\AiUsageStats;
use Filament\Resources\Pages\ListRecords;

class ListAiUsageRecords extends ListRecords
{
    protected static string $resource = AiUsageRecordResource::class;

    protected function getHeaderWidgets(): array
    {
        return [AiUsageStats::class];
    }
}
