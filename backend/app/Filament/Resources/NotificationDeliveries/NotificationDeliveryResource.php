<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\NavigationGroup;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\NotificationDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Pengiriman Notification Engine (PRD Roadmap 5 §6.1): event, penerima & scope, kanal, versi template, jadwal,
 * status Queued/Sent/Delivered/Read/Failed, percobaan & retry, pemicu (aktor/sistem). Kirim ulang & batalkan diaudit.
 */
class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Notifications;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Pengiriman notifikasi';

    protected static ?string $modelLabel = 'Pengiriman notifikasi';

    protected static ?string $pluralModelLabel = 'Pengiriman notifikasi';

    protected static ?string $slug = 'notification-deliveries';

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('notifications.manage') || $u?->isSuperAdmin());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = NotificationDelivery::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Gagal dalam 7 hari terakhir';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['event.actor', 'organization']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable()
                    ->description(fn (NotificationDelivery $record) => $record->scheduled_at && $record->status === 'queued' ? 'Terjadwal '.$record->scheduled_at->format('d M H:i') : null),
                TextColumn::make('event.key')->label('Peristiwa')->searchable()
                    ->formatStateUsing(fn ($state) => NotificationTemplate::eventLabels()[$state] ?? $state)
                    ->description(fn (NotificationDelivery $record) => str($record->content['title'] ?? '')->limit(70)),
                TextColumn::make('audience')->label('Scope')->badge()->color('gray')->formatStateUsing(fn ($state) => NotificationTemplate::AUDIENCES[$state] ?? $state)
                    ->description(fn (NotificationDelivery $record) => $record->organization?->name()),
                TextColumn::make('channel')->label('Kanal')->badge()->color('gray')->formatStateUsing(fn ($state) => NotificationTemplate::CHANNELS[$state] ?? $state),
                TextColumn::make('recipient_name')->label('Penerima')->searchable(['recipient_name', 'recipient'])->description(fn (NotificationDelivery $record) => $record->recipient),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => NotificationDelivery::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'read', 'delivered' => 'success', 'sent' => 'info', 'queued' => 'warning', 'failed' => 'danger', default => 'gray',
                    })
                    ->description(fn (NotificationDelivery $record) => $record->status === 'queued' && $record->next_attempt_at ? 'Coba lagi '.$record->next_attempt_at->format('H:i') : null),
                TextColumn::make('attempts')->label('Percobaan')->formatStateUsing(fn ($state, NotificationDelivery $record) => $state.'/'.$record->max_attempts)->alignCenter(),
                TextColumn::make('template_version')->label('Versi')->formatStateUsing(fn ($state) => $state ? 'v'.$state : '-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event.actor.name')->label('Pemicu')->placeholder('Sistem')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Galat terakhir')->limit(50)->tooltip(fn (NotificationDelivery $record) => $record->last_error)->placeholder('-')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(NotificationDelivery::STATUSES),
                SelectFilter::make('channel')->label('Kanal')->options(NotificationTemplate::CHANNELS),
                SelectFilter::make('audience')->label('Scope')->options(NotificationTemplate::AUDIENCES),
                SelectFilter::make('event_key')->label('Peristiwa')->options(NotificationTemplate::eventLabels())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $key) => $q->whereHas('event', fn (Builder $e) => $e->where('key', $key)))),
                Filter::make('today')->label('Hari ini')->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ])
            ->recordActions([
                Action::make('detail')->label('Detail')->icon('heroicon-o-eye')->color('gray')->modalSubmitAction(false)
                    ->modalHeading(fn (NotificationDelivery $record) => 'Pengiriman #'.$record->id)
                    ->schema([
                        Section::make('Pesan')->columns(2)->components([
                            TextEntry::make('content.title')->label('Judul')->columnSpanFull(),
                            TextEntry::make('content.body')->label('Isi')->columnSpanFull(),
                            TextEntry::make('content.wa_body')->label('Isi WhatsApp')->placeholder('-')->columnSpanFull()->visible(fn (NotificationDelivery $record) => $record->channel === 'whatsapp'),
                            TextEntry::make('content.url')->label('Tautan')->placeholder('-'),
                            TextEntry::make('template_version')->label('Versi template')->formatStateUsing(fn ($state) => $state ? 'v'.$state : '-'),
                        ]),
                        Section::make('Status')->columns(3)->components([
                            TextEntry::make('event.dedupe_key')->label('Kunci event')->placeholder('-')->fontFamily('mono'),
                            TextEntry::make('event.actor.name')->label('Dipicu oleh')->placeholder('Sistem'),
                            TextEntry::make('provider_message_id')->label('ID penyedia')->placeholder('-')->fontFamily('mono'),
                            TextEntry::make('sent_at')->label('Terkirim')->dateTime('d M Y H:i:s')->placeholder('-'),
                            TextEntry::make('delivered_at')->label('Diterima')->dateTime('d M Y H:i:s')->placeholder('-'),
                            TextEntry::make('read_at')->label('Dibaca')->dateTime('d M Y H:i:s')->placeholder('-'),
                        ]),
                        RepeatableEntry::make('logs')->label('Riwayat percobaan')->columns(3)->placeholder('Belum ada percobaan')->schema([
                            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                            TextEntry::make('status')->label('Hasil')->badge()->formatStateUsing(fn ($state) => NotificationLog::STATUSES[$state] ?? $state)
                                ->color(fn ($state) => $state === 'sent' ? 'success' : 'danger'),
                            TextEntry::make('error')->label('Galat')->placeholder('-'),
                        ]),
                    ]),
                Action::make('retry')->label('Kirim ulang')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (NotificationDelivery $record) => in_array($record->status, ['failed', 'cancelled'], true))
                    ->action(function (NotificationDelivery $record, NotificationDispatcher $dispatcher): void {
                        $d = $dispatcher->retry($record, auth()->user());
                        $d->status === 'failed'
                            ? Notification::make()->title('Masih gagal')->body($d->last_error)->danger()->send()
                            : Notification::make()->title('Pengiriman berhasil: '.$d->statusLabel())->success()->send();
                    }),
                Action::make('cancel')->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (NotificationDelivery $record) => $record->status === 'queued')
                    ->action(function (NotificationDelivery $record, NotificationDispatcher $dispatcher): void {
                        $dispatcher->cancel($record, auth()->user());
                        Notification::make()->title('Pengiriman dibatalkan')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('retryFailed')->label('Kirim ulang yang gagal')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->action(function (Collection $records, NotificationDispatcher $dispatcher): void {
                        $failed = $records->filter(fn (NotificationDelivery $d) => $d->status === 'failed');
                        $ok = $failed->filter(fn (NotificationDelivery $d) => $dispatcher->retry($d, auth()->user())->status !== 'failed')->count();
                        Notification::make()->title("$ok dari {$failed->count()} pengiriman berhasil dikirim ulang")->success()->send();
                    }),
                BulkAction::make('cancelQueued')->label('Batalkan yang antre')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                    ->action(function (Collection $records, NotificationDispatcher $dispatcher): void {
                        $queued = $records->filter(fn (NotificationDelivery $d) => $d->status === 'queued');
                        $queued->each(fn (NotificationDelivery $d) => $dispatcher->cancel($d, auth()->user()));
                        Notification::make()->title($queued->count().' pengiriman dibatalkan')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationDeliveries::route('/')];
    }
}
