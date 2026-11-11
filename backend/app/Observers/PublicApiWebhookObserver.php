<?php

namespace App\Observers;

use App\Models\ApiClient;
use App\Models\Certificate;
use App\Models\Registration;
use App\Services\WebhookService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Picu webhook API Publik untuk pendaftaran yang dibuat klien API: perubahan status pendaftaran,
 * serta sertifikat peserta (dari pendaftaran tsb.) yang terbit/dicabut. Dijalankan setelah transaksi commit.
 */
class PublicApiWebhookObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Model $model): void
    {
        if ($model instanceof Registration) {
            $this->registration($model);
        } elseif ($model instanceof Certificate) {
            $this->certificate($model);
        }
    }

    private function registration(Registration $r): void
    {
        if (! $r->api_client_id || ! $r->wasChanged('status')) {
            return;
        }
        $client = ApiClient::query()->find($r->api_client_id);
        if ($client) {
            app(WebhookService::class)->emit($client, 'registration.status_changed', WebhookService::registrationData($r) + [
                // dijalankan setelah commit → nilai lama diambil dari getPrevious() (getOriginal sudah tersinkron)
                'previous_status' => $r->getPrevious()['status'] ?? null,
            ]);
        }
    }

    private function certificate(Certificate $c): void
    {
        if (! $c->wasChanged('status') || ! in_array($c->status, ['issued', 'revoked'], true)) {
            return;
        }
        $r = $c->enrollment?->registration;
        if (! $r?->api_client_id) {
            return;
        }
        $client = ApiClient::query()->find($r->api_client_id);
        if ($client) {
            app(WebhookService::class)->emit($client, 'certificate.'.$c->status, WebhookService::certificateData($c, $r));
        }
    }
}
