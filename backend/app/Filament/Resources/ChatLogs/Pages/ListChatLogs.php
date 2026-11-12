<?php

namespace App\Filament\Resources\ChatLogs\Pages;

use App\Filament\Resources\ChatLogs\ChatLogResource;
use App\Filament\Widgets\ChatbotStats;
use Filament\Resources\Pages\ListRecords;

class ListChatLogs extends ListRecords
{
    protected static string $resource = ChatLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ChatbotStats::class];
    }
}
