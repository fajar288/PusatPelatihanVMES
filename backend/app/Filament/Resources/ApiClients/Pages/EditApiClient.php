<?php

namespace App\Filament\Resources\ApiClients\Pages;

use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Services\WebhookService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Edit klien + ganti API key, buat ulang secret webhook, uji webhook. Semua perubahan tercatat di audit log. */
class EditApiClient extends EditRecord
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rotateKey')->label('Ganti API key')->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                ->modalDescription('Key lama langsung tidak berlaku. Pastikan klien siap memperbarui key di sistemnya.')
                ->action(function (ApiClient $record): void {
                    $key = $record->generateKey();
                    $record->save();
                    AuditLog::record('api_client.key_rotated', $record, null, ['key_prefix' => $record->key_prefix]);
                    ApiClientResource::revealSecret('API key baru dibuat', ['API key' => $key]);
                    $this->refreshFormData(['key_rotated_at']);
                }),
            Action::make('rotateSecret')->label(fn (ApiClient $record) => filled($record->webhook_secret) ? 'Ganti secret webhook' : 'Buat secret webhook')
                ->icon('heroicon-o-finger-print')->color('gray')->requiresConfirmation()
                ->visible(fn (ApiClient $record) => filled($record->webhook_url))
                ->action(function (ApiClient $record): void {
                    $secret = $record->generateWebhookSecret();
                    $record->save();
                    AuditLog::record('api_client.webhook_secret_rotated', $record, null, ['webhook_url' => $record->webhook_url]);
                    ApiClientResource::revealSecret('Secret webhook baru', ['Secret webhook' => $secret]);
                }),
            Action::make('pingWebhook')->label('Uji webhook')->icon('heroicon-o-signal')->color('gray')
                ->visible(fn (ApiClient $record) => filled($record->webhook_url) && filled($record->webhook_secret))
                ->action(function (ApiClient $record, WebhookService $webhooks): void {
                    $d = $webhooks->ping($record);
                    $d->status === 'delivered'
                        ? Notification::make()->title("Webhook terkirim (HTTP {$d->response_status})")->success()->send()
                        : Notification::make()->title('Webhook gagal')->body($d->error)->danger()->send();
                }),
            DeleteAction::make()->after(fn (ApiClient $record) => AuditLog::record('api_client.deleted', null, ['name' => $record->name, 'key_prefix' => $record->key_prefix], null)),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ApiClient $record */
        $before = $record->only(['name', 'scopes', 'rate_limit', 'allowed_ips', 'is_active', 'expires_at', 'webhook_url', 'webhook_events']);
        $record->fill($data);
        $secret = filled($record->webhook_url) && blank($record->webhook_secret) ? $record->generateWebhookSecret() : null;
        $record->save();
        AuditLog::record('api_client.updated', $record, $before, $record->only(array_keys($before)));
        if ($secret) {
            ApiClientResource::revealSecret('Secret webhook dibuat', ['Secret webhook' => $secret]);
        }

        return $record;
    }
}
