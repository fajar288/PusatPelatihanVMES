<?php

namespace App\Filament\Resources\CertificateTemplates\Pages;

use App\Filament\Resources\CertificateTemplates\CertificateTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCertificateTemplates extends ListRecords
{
    protected static string $resource = CertificateTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
