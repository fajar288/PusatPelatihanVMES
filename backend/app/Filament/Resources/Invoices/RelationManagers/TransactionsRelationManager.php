<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Models\PaymentTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Riwayat transaksi pembayaran invoice ini + aksi verifikasi/tolak. */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transaksi pembayaran';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['submitter', 'verifier', 'statementLine']))
            ->columns(PaymentTransactionResource::columns(false))
            ->filters([SelectFilter::make('status')->options(PaymentTransaction::STATUSES)])
            ->recordActions(PaymentTransactionResource::reviewActions());
    }
}
