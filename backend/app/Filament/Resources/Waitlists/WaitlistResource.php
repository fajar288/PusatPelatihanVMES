<?php

namespace App\Filament\Resources\Waitlists;

use App\Filament\Resources\Waitlists\Pages\CreateWaitlist;
use App\Filament\Resources\Waitlists\Pages\EditWaitlist;
use App\Filament\Resources\Waitlists\Pages\ListWaitlists;
use App\Filament\Resources\Waitlists\Schemas\WaitlistForm;
use App\Filament\Resources\Waitlists\Tables\WaitlistsTable;
use App\Models\Waitlist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WaitlistResource extends Resource
{
    protected static ?string $model = Waitlist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Daftar tunggu';

    protected static ?string $modelLabel = 'Daftar tunggu';

    protected static ?string $pluralModelLabel = 'Daftar tunggu';

    public static function form(Schema $schema): Schema
    {
        return WaitlistForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WaitlistsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWaitlists::route('/'),
            'create' => CreateWaitlist::route('/create'),
            'edit' => EditWaitlist::route('/{record}/edit'),
        ];
    }
}
