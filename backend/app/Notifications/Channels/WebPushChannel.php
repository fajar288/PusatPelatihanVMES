<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Support\Push\PushSender;
use Illuminate\Notifications\Notification;
use RuntimeException;

/** Kanal notifikasi Web Push: kirim ke semua perangkat pengguna; langganan kedaluwarsa (404/410) dihapus. */
class WebPushChannel
{
    public function __construct(private readonly PushSender $sender) {}

    /** @return array{sent: int, failed: int} */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush')) {
            return ['sent' => 0, 'failed' => 0];
        }
        if (! $this->sender->configured()) {
            throw new RuntimeException('Web push belum dikonfigurasi (VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY).');
        }
        $payload = $notification->toWebPush($notifiable);
        $subs = $notifiable->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            throw new RuntimeException('Pengguna belum mengaktifkan push di perangkat mana pun.');
        }

        $sent = 0;
        $reasons = [];
        foreach ($subs as $sub) {
            $r = $this->sender->send($sub, $payload);
            if ($r['ok']) {
                $sent++;
                $sub->forceFill(['last_used_at' => now()])->saveQuietly();
            } else {
                $reasons[] = ($r['status'] ? 'HTTP '.$r['status'].' ' : '').$r['reason'];
                if ($r['expired']) {
                    $sub->delete();
                }
            }
        }
        if ($sent === 0) {
            throw new RuntimeException('Push gagal: '.implode('; ', array_unique($reasons)));
        }

        return ['sent' => $sent, 'failed' => count($reasons)];
    }
}
