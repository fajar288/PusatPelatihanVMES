<?php

namespace App\Filament\Resources\ApiClients\Pages;

use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Models\ApiClient;
use App\Models\AuditLog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Buat klien: API key (+ secret webhook bila ada URL) dibuat otomatis & ditampilkan sekali. */
class CreateApiClient extends CreateRecord
{
    protected static string $resource = ApiClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $client = new ApiClient($data + ['created_by' => auth()->id()]);
        $key = $client->generateKey();
        $secret = filled($data['webhook_url'] ?? null) ? $client->generateWebhookSecret() : null;
        $client->save();

        AuditLog::record('api_client.created', $client, null, ['name' => $client->name, 'scopes' => $client->scopes, 'key_prefix' => $client->key_prefix]);
        ApiClientResource::revealSecret('Klien API dibuat', ['API key' => $key, 'Secret webhook' => $secret]);

        return $client;
    }

    protected function getCreatedNotification(): null
    {
        return null;
    }
}
