<?php

namespace App\Filament\Resources\ApiClients\Pages;

use App\Filament\Resources\ApiClients\ApiClientResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('docs')->label('Dokumentasi API')->icon('heroicon-o-book-open')->color('gray')->url(route('docs.api'))->openUrlInNewTab(),
            CreateAction::make(),
        ];
    }
}
