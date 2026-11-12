<?php

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Widgets\NotificationDeliveryStats;
use Filament\Resources\Pages\ListRecords;

class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    protected function getHeaderWidgets(): array
    {
        return [NotificationDeliveryStats::class];
    }
}
