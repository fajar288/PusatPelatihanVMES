<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\FinanceFields;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Invoice dari pendaftaran peserta (baca saja), tautan ke resource Invoice. */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoice';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->label('Nomor')->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => Invoice::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => FinanceFields::invoiceColor($state)),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => FinanceFields::rupiah($state)),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-'),
                TextColumn::make('created_at')->label('Dibuat')->date('d M Y'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('open')->label('Buka')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn () => InvoiceResource::canViewAny())
                    ->url(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
