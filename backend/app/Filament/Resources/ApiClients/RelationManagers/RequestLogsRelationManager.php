<?php

namespace App\Filament\Resources\ApiClients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Log permintaan API (hanya baca). */
class RequestLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'requestLogs';

    protected static ?string $title = 'Log permintaan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                TextColumn::make('method')->label('Metode')->badge()->color('gray'),
                TextColumn::make('path')->label('Path')->fontFamily('mono')->size('sm')->wrap()->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn ($state) => $state >= 500 ? 'danger' : ($state >= 400 ? 'warning' : 'success')),
                TextColumn::make('duration_ms')->label('Durasi')->suffix(' ms')->numeric(),
                TextColumn::make('ip')->label('IP'),
            ])
            ->filters([
                SelectFilter::make('result')->label('Hasil')->options(['ok' => 'Berhasil (2xx)', 'client' => 'Galat klien (4xx)', 'server' => 'Galat server (5xx)'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'ok' => $query->whereBetween('status', [200, 299]),
                        'client' => $query->whereBetween('status', [400, 499]),
                        'server' => $query->where('status', '>=', 500),
                        default => $query,
                    }),
            ])
            ->paginated([25, 50, 100])
            ->headerActions([])
            ->recordActions([]);
    }
}
