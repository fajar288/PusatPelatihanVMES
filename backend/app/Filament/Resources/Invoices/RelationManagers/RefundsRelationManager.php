<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Resources\PaymentRefunds\PaymentRefundResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Refund';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['requester', 'decider', 'completer']))
            ->columns(PaymentRefundResource::columns(false))
            ->recordActions(PaymentRefundResource::workflowActions());
    }
}
