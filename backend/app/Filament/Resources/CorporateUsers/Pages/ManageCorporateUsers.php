<?php

namespace App\Filament\Resources\CorporateUsers\Pages;

use App\Filament\Resources\CorporateUsers\CorporateUserResource;
use App\Filament\Support\EnterpriseFields;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCorporateUsers extends ManageRecords
{
    protected static string $resource = CorporateUserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah pengguna')
            ->using(fn (array $data, CreateAction $action) => EnterpriseFields::attempt($action, fn () => CorporateUserResource::createViaService($data)))];
    }
}
