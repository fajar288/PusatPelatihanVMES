<?php

namespace App\Notifications\Channels;

use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Notifications\Notification;
use RuntimeException;

/** Kanal WhatsApp lewat antarmuka WhatsAppSender (driver `log` sampai penyedia diputuskan). */
class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppSender $sender) {}

    /** @return array{provider: string, id: string, to: string} */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! $this->sender->enabled()) {
            throw new RuntimeException('Kanal WhatsApp dimatikan (WHATSAPP_ENABLED=false).');
        }
        if (! method_exists($notification, 'toWhatsApp')) {
            throw new RuntimeException('Notifikasi tidak mendukung WhatsApp.');
        }
        $to = LogWhatsAppSender::normalize(method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('whatsapp', $notification) : null);
        if (! $to) {
            throw new RuntimeException('Nomor WhatsApp penerima tidak tersedia/tidak valid.');
        }

        return ['provider' => $this->sender->provider(), 'id' => $this->sender->send($to, $notification->toWhatsApp($notifiable)), 'to' => $to];
    }
}
