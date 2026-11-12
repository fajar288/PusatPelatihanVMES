<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Services\OrganizationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Dibuat lewat OrganizationService (kode ORG-xxxx + audit). Bawaan: hanya admin VMES yang membuat organisasi. */
class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(OrganizationService::class)->create($data, auth()->user());
    }
}
