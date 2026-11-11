<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Client as GuzzleClient;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/** Web Push standar (VAPID) lewat minishlink/web-push. Kunci: VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT. */
class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    public function configured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function publicKey(): ?string
    {
        return $this->configured() ? config('services.webpush.public_key') : null;
    }

    public function send(PushSubscription $subscription, array $payload): array
    {
        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ['TTL' => 60 * 60 * 24, 'urgency' => 'normal', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['tag'] ?? 'ppti')), 0, 32)],
            );

            return [
                'ok' => $report->isSuccess(),
                'status' => $report->getResponse()?->getStatusCode(),
                'expired' => $report->isSubscriptionExpired(),
                'reason' => $report->isSuccess() ? null : $report->getReason(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'expired' => false, 'reason' => $e->getMessage()];
        }
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject') ?: rtrim((string) config('app.url'), '/'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], [], new GuzzleClient(['timeout' => (int) config('services.public_api.webhook_timeout', 10)]));
    }
}
