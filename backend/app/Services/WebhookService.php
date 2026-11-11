<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\ApiClient;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhook keluar API Publik: setiap event dicatat di `webhook_deliveries` lalu dikirim lewat antrean (DeliverWebhook)
 * dengan tanda tangan HMAC-SHA256 `X-PPTI-Signature: t=<unix>,v1=<hex>` atas "<t>.<body>".
 */
class WebhookService
{
    public function emit(ApiClient $client, string $event, array $data, bool $force = false): ?WebhookDelivery
    {
        if (! $force && ! $client->wantsWebhook($event)) {
            return null;
        }
        $uuid = (string) Str::uuid();
        $delivery = $client->webhookDeliveries()->create([
            'uuid' => $uuid,
            'event' => $event,
            'payload' => ['id' => $uuid, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data],
            'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }

    /** Event uji `ping` dari panel admin — dikirim langsung (tanpa antrean) agar hasilnya terlihat seketika. */
    public function ping(ApiClient $client): WebhookDelivery
    {
        $uuid = (string) Str::uuid();
        $delivery = $client->webhookDeliveries()->create([
            'uuid' => $uuid,
            'event' => 'ping',
            'payload' => ['id' => $uuid, 'event' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['client' => $client->name, 'message' => 'Uji webhook dari PPTI VMES']],
            'status' => 'pending',
        ]);
        $this->attempt($delivery);

        return $delivery->refresh();
    }

    /** Satu percobaan kirim. true = 2xx. Dipanggil job (dengan retry) maupun aksi "Kirim ulang" admin. */
    public function attempt(WebhookDelivery $delivery): bool
    {
        $client = $delivery->client;
        if (! $client || ! filled($client->webhook_url) || ! filled($client->webhook_secret)) {
            $delivery->update(['status' => 'failed', 'error' => 'Klien tidak memiliki URL/secret webhook.']);

            return false;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) now()->timestamp;
        $delivery->increment('attempts');

        try {
            $res = Http::timeout((int) config('services.public_api.webhook_timeout', 10))
                ->withHeaders([
                    'User-Agent' => 'PPTI-Webhook/1.0',
                    'X-PPTI-Event' => $delivery->event,
                    'X-PPTI-Delivery' => $delivery->uuid,
                    'X-PPTI-Signature' => self::signatureHeader($client->webhook_secret, $ts, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($client->webhook_url);

            $ok = $res->successful();
            $delivery->update([
                'status' => $ok ? 'delivered' : 'failed',
                'response_status' => $res->status(),
                'response_body' => Str::limit($res->body(), 2000, ''),
                'error' => $ok ? null : 'HTTP '.$res->status(),
                'delivered_at' => $ok ? now() : $delivery->delivered_at,
            ]);

            return $ok;
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'response_status' => null, 'error' => Str::limit($e->getMessage(), 1000, '')]);

            return false;
        }
    }

    public static function signatureHeader(string $secret, string $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array<string, mixed> */
    public static function registrationData(Registration $r): array
    {
        $r->loadMissing('schedule.package');

        return [
            'number' => $r->registration_number,
            'external_ref' => $r->external_ref,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'status_note' => $r->status_note,
            'schedule_code' => $r->schedule?->code,
            'program' => $r->schedule?->package?->title,
            'participants_count' => $r->participants_count,
            'total' => $r->total !== null ? (float) $r->total : null,
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function certificateData(Certificate $c, Registration $r): array
    {
        return [
            'number' => $c->number,
            'status' => $c->status,
            'type' => $c->type,
            'recipient_name' => $c->recipient_name,
            'program' => $c->program_title,
            'class_code' => $c->class_code,
            'issued_at' => $c->issued_at?->toDateString(),
            'valid_until' => $c->valid_until?->toDateString(),
            'revoked_at' => $c->revoked_at?->toDateString(),
            'verification_url' => $c->verificationUrl(),
            'registration_number' => $r->registration_number,
            'external_ref' => $r->external_ref,
        ];
    }
}
