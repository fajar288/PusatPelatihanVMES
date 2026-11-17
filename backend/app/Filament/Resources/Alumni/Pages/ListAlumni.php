<?php

namespace App\Filament\Resources\Alumni\Pages;

use App\Filament\Resources\Alumni\AlumniResource;
use App\Filament\Support\ExportActions;
use Filament\Resources\Pages\ListRecords;

class ListAlumni extends ListRecords
{
    protected static string $resource = AlumniResource::class;

    protected function getHeaderActions(): array
    {
        return ExportActions::make('alumni', fn () => ExportActions::filterQuery($this->tableFilters ?? [], ['year', 'program']));
    }
}
