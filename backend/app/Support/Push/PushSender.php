<?php

namespace App\Support\Push;

use App\Models\PushSubscription;

/** Pengirim Web Push (diganti versi palsu di tes). */
interface PushSender
{
    public function configured(): bool;

    public function publicKey(): ?string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: ?int, expired: bool, reason: ?string}
     */
    public function send(PushSubscription $subscription, array $payload): array;
}
