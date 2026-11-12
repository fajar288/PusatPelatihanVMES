<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\CertificateIssued;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\RegistrationReceived;
use App\Notifications\RegistrationStatusChanged;
use App\Notifications\ScheduleChanged;
use App\Notifications\SystemNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;

/** Catat setiap notifikasi terkirim (semua kelas & kanal, termasuk email transaksional) ke `notification_logs`. */
class LogSentNotification
{
    private const CATEGORIES = [
        RegistrationReceived::class => 'registration',
        RegistrationStatusChanged::class => 'registration',
        ScheduleChanged::class => 'schedule',
        CertificateIssued::class => 'certificate',
    ];

    public function handle(NotificationSent $event): void
    {
        $n = $event->notification;
        if ($n instanceof SystemNotification && $n->deliveryId) {
            return; // pengiriman Notification Engine dicatat NotificationDispatcher (per percobaan)
        }
        $notifiable = $event->notifiable;
        $channel = match ($event->channel) {
            WebPushChannel::class => 'webpush',
            WhatsAppChannel::class => 'whatsapp',
            default => Str::limit((string) $event->channel, 20, ''),
        };
        $recipient = match ($channel) {
            'mail' => $this->mailRecipient($notifiable, $n),
            'webpush' => is_array($event->response) ? ($event->response['sent'] ?? 0).' perangkat' : null,
            'whatsapp' => is_array($event->response) ? ($event->response['to'] ?? null) : null,
            default => $notifiable instanceof User ? $notifiable->email : null,
        };
        $system = $n instanceof SystemNotification;

        NotificationLog::query()->create([
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'notifiable_type' => method_exists($notifiable, 'getMorphClass') ? $notifiable->getMorphClass() : null,
            'notifiable_id' => $notifiable->id ?? null,
            'notification' => $system ? $n->key : class_basename($n),
            'category' => $system ? $n->category : (self::CATEGORIES[$n::class] ?? 'account'),
            'channel' => $channel,
            'recipient' => $recipient ? Str::limit($recipient, 250, '') : null,
            'status' => 'sent',
            'subject' => $system ? Str::limit($n->title, 250, '') : null,
            'dedupe_key' => $system ? $n->dedupeKey : null,
        ]);
    }

    private function mailRecipient(object $notifiable, object $n): ?string
    {
        $route = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail', $n) : null;
        if (is_array($route)) {
            return implode(', ', array_map(fn ($k, $v) => is_string($k) ? $k : $v, array_keys($route), $route));
        }

        return is_string($route) ? $route : null;
    }
}
