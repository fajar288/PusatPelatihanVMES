<?php

namespace App\Filament\Resources\SponsorshipTransfers\Pages;

use App\Filament\Resources\SponsorshipTransfers\SponsorshipTransferResource;
use App\Filament\Support\EnterpriseFields;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSponsorshipTransfers extends ManageRecords
{
    protected static string $resource = SponsorshipTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ajukan transfer')
            ->using(fn (array $data, CreateAction $action) => EnterpriseFields::attempt($action, fn () => SponsorshipTransferResource::createViaService($data)))];
    }
}
