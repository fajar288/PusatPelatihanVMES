<?php

namespace App\Filament\Resources\ApiClients\RelationManagers;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Riwayat pengiriman webhook + lihat payload & kirim ulang. */
class WebhookDeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'webhookDeliveries';

    protected static ?string $title = 'Pengiriman webhook';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                TextColumn::make('event')->label('Event')->badge()->color('info'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => WebhookDelivery::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'delivered' => 'success', 'failed' => 'danger', default => 'warning'
                    }),
                TextColumn::make('attempts')->label('Percobaan')->numeric(),
                TextColumn::make('response_status')->label('HTTP')->placeholder('-'),
                TextColumn::make('error')->label('Galat')->limit(60)->placeholder('-')->wrap(),
            ])
            ->filters([SelectFilter::make('status')->options(WebhookDelivery::STATUSES)])
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->recordActions([
                Action::make('payload')->label('Payload')->icon('heroicon-o-code-bracket')->color('gray')
                    ->modalHeading(fn (WebhookDelivery $record) => $record->event.' — '.$record->uuid)
                    ->modalContent(fn (WebhookDelivery $record) => new HtmlString(
                        '<pre style="font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:60vh;overflow:auto">'.e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>'
                        .($record->response_body ? '<p style="margin-top:12px;font-weight:600">Respons terakhir</p><pre style="font-size:12px;white-space:pre-wrap;word-break:break-all">'.e($record->response_body).'</pre>' : '')
                    ))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
                Action::make('resend')->label('Kirim ulang')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->visible(fn (WebhookDelivery $record) => $record->status !== 'delivered')
                    ->action(function (WebhookDelivery $record, WebhookService $webhooks): void {
                        $webhooks->attempt($record)
                            ? Notification::make()->title('Webhook terkirim')->success()->send()
                            : Notification::make()->title('Masih gagal')->body($record->refresh()->error)->danger()->send();
                    }),
            ]);
    }
}
