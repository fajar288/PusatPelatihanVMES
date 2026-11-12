<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\AuditLog;
use App\Support\FinanceSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Keputusan terbuka Roadmap 5: tarif pajak bawaan 0% (baris pajak disembunyikan bila 0). Berlaku untuk invoice BARU.
            Action::make('settings')->label('Pengaturan keuangan')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->modalDescription('Berlaku untuk invoice yang dibuat setelah disimpan; invoice lama tidak berubah.')
                ->fillForm(fn () => FinanceSettings::all())
                ->schema([
                    TextInput::make('tax_rate')->label('Tarif pajak (%)')->numeric()->minValue(0)->maxValue(100)->required()
                        ->helperText('0 = baris pajak tidak tampil di invoice.'),
                    TextInput::make('tax_label')->label('Nama pajak')->maxLength(40)->default('PPN'),
                    TextInput::make('due_days')->label('Jatuh tempo pendaftaran (hari)')->integer()->minValue(1)->maxValue(90)->required(),
                    TextInput::make('corporate_due_days')->label('Jatuh tempo invoice perusahaan (hari)')->integer()->minValue(1)->maxValue(120)->required(),
                    Textarea::make('invoice_notes')->label('Catatan standar di invoice')->rows(3)->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $before = FinanceSettings::all();
                    FinanceSettings::save($data);
                    AuditLog::record('finance.settings_updated', null, $before, FinanceSettings::all());
                    Notification::make()->title('Pengaturan keuangan disimpan')->success()->send();
                }),
        ];
    }
}
