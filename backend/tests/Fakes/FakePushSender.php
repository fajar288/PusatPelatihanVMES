<?php

namespace Tests\Fakes;

use App\Models\PushSubscription;
use App\Support\Push\PushSender;

/** Pengganti Web Push di tes: mencatat payload; endpoint berisi "expired" → 410, "fail" → 500. */
class FakePushSender implements PushSender
{
    /** @var array<int, array{endpoint: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function __construct(public bool $enabled = true) {}

    public function configured(): bool
    {
        return $this->enabled;
    }

    public function publicKey(): ?string
    {
        return $this->enabled ? 'BFakePublicKeyForTests' : null;
    }

    public function send(PushSubscription $subscription, array $payload): array
    {
        if (str_contains($subscription->endpoint, 'expired')) {
            return ['ok' => false, 'status' => 410, 'expired' => true, 'reason' => 'Gone'];
        }
        if (str_contains($subscription->endpoint, 'fail')) {
            return ['ok' => false, 'status' => 500, 'expired' => false, 'reason' => 'Server error'];
        }
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload];

        return ['ok' => true, 'status' => 201, 'expired' => false, 'reason' => null];
    }
}
