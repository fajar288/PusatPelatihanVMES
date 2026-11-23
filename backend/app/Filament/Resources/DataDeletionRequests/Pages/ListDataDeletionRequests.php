<?php

namespace App\Filament\Resources\DataDeletionRequests\Pages;

use App\Filament\Resources\DataDeletionRequests\DataDeletionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDataDeletionRequests extends ListRecords
{
    protected static string $resource = DataDeletionRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Permintaan peserta untuk menghapus data pribadinya. Tenggat proses '.config('privacy.deletion_deadline_days', 14).' hari sejak diajukan.';
    }
}
