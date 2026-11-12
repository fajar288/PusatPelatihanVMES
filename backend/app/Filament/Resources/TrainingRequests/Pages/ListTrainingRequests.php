<?php

namespace App\Filament\Resources\TrainingRequests\Pages;

use App\Filament\Resources\TrainingRequests\TrainingRequestResource;
use Filament\Resources\Pages\ListRecords;

/** Permintaan dibuat perusahaan dari Corporate Portal (/corporate). */
class ListTrainingRequests extends ListRecords
{
    protected static string $resource = TrainingRequestResource::class;
}
