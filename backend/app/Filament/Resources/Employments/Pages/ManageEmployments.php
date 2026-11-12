<?php

namespace App\Filament\Resources\Employments\Pages;

use App\Filament\Resources\Employments\EmploymentRelationshipResource;
use App\Filament\Support\EnterpriseFields;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEmployments extends ManageRecords
{
    protected static string $resource = EmploymentRelationshipResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah hubungan kerja')
            ->using(fn (array $data, CreateAction $action) => EnterpriseFields::attempt($action, fn () => EmploymentRelationshipResource::createViaService($data)))];
    }
}
