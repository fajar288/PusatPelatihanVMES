<?php

namespace App\Filament\Resources\Registrations\Pages;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\Registrations\RegistrationResource;
use App\Models\Registration;
use App\Services\RegistrationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditRegistration extends EditRecord
{
    protected static string $resource = RegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ubahStatus')
                ->label('Ubah status')
                ->icon('heroicon-o-arrow-path')
                ->schema(fn (Registration $record) => [
                    Select::make('status')->label('Status baru')->required()->native(false)
                        ->options(array_combine(array_map(fn ($s) => $s->value, $record->status->next()), array_map(fn ($s) => $s->label(), $record->status->next()))),
                    Textarea::make('note')->label('Catatan (dikirim ke pendaftar)')->rows(3),
                ])
                ->visible(fn (Registration $record) => $record->status->next() !== [])
                ->action(function (array $data, Registration $record, RegistrationService $service): void {
                    try {
                        $service->transition($record, RegistrationStatus::from($data['status']), auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Status diperbarui: '.$record->refresh()->status->label())->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshFormData(['status', 'status_note']);
                }),
        ];
    }
}
