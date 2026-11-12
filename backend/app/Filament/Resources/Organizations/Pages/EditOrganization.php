<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\EditRecord;

/** Perubahan profil/status/kebijakan tercatat otomatis oleh AuditObserver. Organisasi tidak bisa dihapus (histori enrollment). */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;
}
