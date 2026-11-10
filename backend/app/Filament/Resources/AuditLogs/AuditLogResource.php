<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Jejak audit (hanya baca). */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'Audit log';

    protected static ?string $pluralModelLabel = 'Audit log';

    protected static ?int $navigationSort = 99;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('actor_name')->label('Aktor')->searchable(),
                TextColumn::make('action')->label('Aksi')->searchable()->badge()->color('gray'),
                TextColumn::make('auditable_type')->label('Model')->formatStateUsing(fn ($state) => $state ? class_basename($state) : '-'),
                TextColumn::make('auditable_id')->label('ID'),
                TextColumn::make('after')->label('Perubahan')->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : '-')->limit(80)->tooltip(fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
