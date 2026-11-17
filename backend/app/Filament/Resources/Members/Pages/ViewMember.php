<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->user?->name ?? $this->getRecord()->member_number;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Ubah profil')];
    }
}
