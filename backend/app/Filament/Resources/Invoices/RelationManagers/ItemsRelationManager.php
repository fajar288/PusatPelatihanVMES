<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Item invoice (hanya baca: dihitung dari pendaftaran / penawaran). */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('description')->label('Deskripsi')->wrap(),
                TextColumn::make('quantity')->label('Qty')->numeric(decimalPlaces: 0),
                TextColumn::make('unit_price')->label('Harga satuan')->money('IDR', locale: 'id'),
                TextColumn::make('amount')->label('Jumlah')->money('IDR', locale: 'id'),
            ]);
    }
}
