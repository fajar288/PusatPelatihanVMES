<?php

namespace App\Filament\Resources\ApiClients\Tables;

use App\Models\ApiClient;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ApiClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Klien')->searchable()->description(fn (ApiClient $record) => $record->boundOrganization ? 'Enterprise · '.$record->boundOrganization->code.' '.$record->boundOrganization->name() : $record->organization),
                TextColumn::make('key_prefix')->label('API key')->formatStateUsing(fn ($state, ApiClient $record) => $record->maskedKey())->fontFamily('mono')->size('sm'),
                TextColumn::make('scopes')->label('Scope')->badge()->separator(','),
                TextColumn::make('rate_limit')->label('Limit/mnt')->numeric(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('expires_at')->label('Kedaluwarsa')->date('d M Y')->placeholder('-')
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
                TextColumn::make('last_used_at')->label('Terakhir dipakai')->since()->placeholder('Belum pernah')->sortable(),
                TextColumn::make('webhook_url')->label('Webhook')->formatStateUsing(fn ($state) => parse_url((string) $state, PHP_URL_HOST) ?: $state)->placeholder('-'),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Aktif')])
            ->recordActions([EditAction::make()]);
    }
}
