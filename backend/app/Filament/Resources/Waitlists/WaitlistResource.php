<?php

namespace App\Filament\Resources\Waitlists;

use App\Filament\NavigationGroup;
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
use UnitEnum;

class WaitlistResource extends Resource
{
    protected static ?string $model = Waitlist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::TmsRegistration;

    protected static ?int $navigationSort = 2;

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
