<?php

namespace App\Filament\Resources\CertificateTemplates\Pages;

use App\Filament\Resources\CertificateTemplates\CertificateTemplateResource;
use App\Models\CertificateTemplate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCertificateTemplate extends EditRecord
{
    protected static string $resource = CertificateTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Contoh PDF')->icon('heroicon-o-eye')->url(fn (CertificateTemplate $record) => route('admin.certificate_templates.preview', $record))->openUrlInNewTab(),
            DeleteAction::make()->visible(fn (CertificateTemplate $record) => ! $record->certificates()->exists()),
        ];
    }
}
