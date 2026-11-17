<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Filament\Support\ExportActions;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return ExportActions::make('members', fn () => ExportActions::filterQuery($this->tableFilters ?? [], ['status', 'company', 'program']));
    }
}
