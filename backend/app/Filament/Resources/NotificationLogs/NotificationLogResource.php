<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Log notifikasi: status terkirim/gagal per kanal untuk semua notifikasi (hanya baca, PRD §5.12). */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Notifikasi';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Log notifikasi';

    protected static ?string $modelLabel = 'Log notifikasi';

    protected static ?string $pluralModelLabel = 'Log notifikasi';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('notifications.manage') || $u?->isSuperAdmin());
    }

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
                TextColumn::make('notification')->label('Notifikasi')->searchable()->description(fn (NotificationLog $record) => $record->subject),
                TextColumn::make('category')->label('Kategori')->formatStateUsing(fn ($state) => NotificationTemplate::CATEGORIES[$state][0] ?? ucfirst((string) $state))->toggleable(),
                TextColumn::make('channel')->label('Kanal')->badge()->color('gray')->formatStateUsing(fn ($state) => NotificationTemplate::CHANNELS[$state] ?? $state),
                TextColumn::make('recipient')->label('Penerima')->searchable()->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => NotificationLog::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'gray'
                    }),
                TextColumn::make('error')->label('Galat')->limit(60)->tooltip(fn (NotificationLog $record) => $record->error)->placeholder('-')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->options(NotificationLog::STATUSES),
                SelectFilter::make('channel')->label('Kanal')->options(NotificationTemplate::CHANNELS),
                SelectFilter::make('category')->label('Kategori')->options(collect(NotificationTemplate::CATEGORIES)->map(fn ($c) => $c[0])->all() + ['account' => 'Akun']),
                Filter::make('today')->label('Hari ini')->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationLogs::route('/')];
    }
}
