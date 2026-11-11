<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Kirim satu webhook; gagal (non-2xx/timeout) diulang dengan jeda bertahap hingga 5 kali. */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $deliveryId) {}

    /** @return int[] */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(WebhookService $webhooks): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }
        // release (bukan exception) agar antrean sync/dev tidak menggagalkan request yang memicu event
        if (! $webhooks->attempt($delivery) && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts(), 4) - 1]);
        }
    }
}
