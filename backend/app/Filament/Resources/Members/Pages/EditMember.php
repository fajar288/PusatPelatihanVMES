<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/** Ubah profil aman saja (lihat MemberResource::form); perubahan tercatat otomatis lewat AuditObserver (Member.updated). */
class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return 'Ubah profil '.($this->getRecord()->user?->name ?? $this->getRecord()->member_number);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip(MemberResource::SAFE_FIELDS));
    }

    protected function getRedirectUrl(): string
    {
        return MemberResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
