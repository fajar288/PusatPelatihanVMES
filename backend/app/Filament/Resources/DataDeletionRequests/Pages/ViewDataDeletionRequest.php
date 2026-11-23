<?php

namespace App\Filament\Resources\DataDeletionRequests\Pages;

use App\Filament\Resources\DataDeletionRequests\DataDeletionRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDataDeletionRequest extends ViewRecord
{
    protected static string $resource = DataDeletionRequestResource::class;

    public function getTitle(): string
    {
        return 'Permintaan hapus data '.$this->getRecord()->account_label;
    }

    protected function getHeaderActions(): array
    {
        return DataDeletionRequestResource::decisionActions();
    }
}
