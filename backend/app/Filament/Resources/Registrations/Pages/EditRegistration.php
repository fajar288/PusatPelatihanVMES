<?php

namespace App\Filament\Resources\Registrations\Pages;

use App\Enums\RegistrationStatus;
use App\Filament\Resources\Registrations\RegistrationResource;
use App\Models\Registration;
use App\Services\FinanceService;
use App\Services\RegistrationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditRegistration extends EditRecord
{
    protected static string $resource = RegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('promo')->label('Promo / diskon')->icon('heroicon-o-ticket')->color('gray')
                ->schema([
                    TextInput::make('promo_code')->label('Kode promo')->placeholder('HEMAT10'),
                    TextInput::make('manual_discount')->label('atau diskon manual (Rp)')->numeric()->minValue(0),
                    TextInput::make('note')->label('Keterangan diskon')->maxLength(255),
                ])
                ->action(function (array $data, Registration $record, FinanceService $finance): void {
                    try {
                        if (! empty($data['promo_code'])) {
                            $finance->applyPromo($record, $data['promo_code'], auth()->user());
                        } elseif ($data['manual_discount'] !== null && $data['manual_discount'] !== '') {
                            $finance->applyManualDiscount($record, (float) $data['manual_discount'], $data['note'] ?? null, auth()->user());
                        }
                        Notification::make()->title('Total diperbarui: Rp '.number_format((float) $record->refresh()->total, 0, ',', '.'))->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshFormData(['discount', 'total', 'promo_code']);
                }),
            Action::make('termin')->label('Buat tagihan termin')->icon('heroicon-o-banknotes')->color('gray')
                ->schema([
                    TextInput::make('count')->label('Jumlah termin')->numeric()->minValue(1)->maxValue(12)->default(1)->required(),
                    TextInput::make('interval_days')->label('Jarak antar termin (hari)')->numeric()->minValue(1)->default(30)->required(),
                    DatePicker::make('first_due')->label('Jatuh tempo termin pertama')->native(false),
                ])
                ->action(function (array $data, Registration $record, FinanceService $finance): void {
                    try {
                        $p = $finance->createInstallments($record, (int) $data['count'], (int) $data['interval_days'], $data['first_due'] ?? null, auth()->user());
                        Notification::make()->title(count($p).' tagihan dibuat')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
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
