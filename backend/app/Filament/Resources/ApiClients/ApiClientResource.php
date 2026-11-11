<?php

namespace App\Filament\Resources\ApiClients;

use App\Filament\Resources\ApiClients\Pages\CreateApiClient;
use App\Filament\Resources\ApiClients\Pages\EditApiClient;
use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Filament\Resources\ApiClients\RelationManagers\RequestLogsRelationManager;
use App\Filament\Resources\ApiClients\RelationManagers\WebhookDeliveriesRelationManager;
use App\Filament\Resources\ApiClients\Schemas\ApiClientForm;
use App\Filament\Resources\ApiClients\Tables\ApiClientsTable;
use App\Models\ApiClient;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Klien API Publik (Phase 5): API key, scope, rate limit, IP, webhook, log permintaan & pengiriman. Izin api.manage. */
class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Integrasi';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Klien API';

    protected static ?string $modelLabel = 'Klien API';

    protected static ?string $pluralModelLabel = 'Klien API';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('api.manage') || $u?->isSuperAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return ApiClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RequestLogsRelationManager::class, WebhookDeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiClients::route('/'),
            'create' => CreateApiClient::route('/create'),
            'edit' => EditApiClient::route('/{record}/edit'),
        ];
    }

    /** Tampilkan rahasia (API key / secret webhook) sekali dalam notifikasi yang harus ditutup manual. */
    public static function revealSecret(string $title, array $secrets): void
    {
        $rows = collect($secrets)->filter()->map(fn ($v, $label) => '<div style="margin-top:.5rem"><div style="font-size:.75rem;opacity:.75">'.e($label).'</div><code style="user-select:all;word-break:break-all;font-size:.8rem">'.e($v).'</code></div>')->implode('');
        Notification::make()->title($title)->warning()->persistent()
            ->body(new HtmlString('Salin & simpan sekarang &mdash; nilai ini <strong>tidak akan ditampilkan lagi</strong>.'.$rows))
            ->send();
    }
}
