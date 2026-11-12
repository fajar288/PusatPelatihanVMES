<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\SystemNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengirim Notification Engine (PRD Roadmap 5 §6.1): proses pengiriman yang jatuh tempo, retry dengan jeda bertahap,
 * status Queued → Sent → Delivered → Read / Failed, tanda terima penyedia, dan sinkron status "dibaca" notifikasi aplikasi.
 * Setiap percobaan dicatat di `notification_logs` (delivery_id + attempt).
 */
class NotificationDispatcher
{
    /** Proses pengiriman yang jatuh tempo (dipanggil `notifications:dispatch` tiap menit). @return int jumlah diproses */
    public function processDue(int $limit = 200): int
    {
        $n = 0;
        NotificationDelivery::query()->due()->orderBy('id')->limit($limit)->get()
            ->each(function (NotificationDelivery $d) use (&$n): void {
                $this->process($d);
                $n++;
            });

        return $n;
    }

    /** @param list<int> $ids */
    public function processIds(array $ids): void
    {
        NotificationDelivery::query()->whereIn('id', $ids)->orderBy('id')->get()->each(fn (NotificationDelivery $d) => $this->process($d));
    }

    /** Satu percobaan kirim. Aman dipanggil bersamaan: pengiriman "diklaim" atomik sebelum dikirim. */
    public function process(NotificationDelivery $d): NotificationDelivery
    {
        $claimed = NotificationDelivery::query()->whereKey($d->id)->due()
            ->update(['locked_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return $d->refresh();
        }
        $d->refresh()->loadMissing('user', 'event');
        $n = $this->notificationFor($d);

        try {
            $response = $this->deliver($d, $n);
        } catch (Throwable $e) {
            return $this->failAttempt($d, $e->getMessage());
        }

        $now = now();
        $inApp = $d->channel === 'database';
        $d->update([
            'status' => $inApp ? 'delivered' : 'sent',   // aplikasi: tersimpan di kotak masuk penerima = diterima
            'sent_at' => $now,
            'delivered_at' => $inApp ? $now : null,
            'locked_at' => null, 'next_attempt_at' => null, 'last_error' => null,
            'provider' => match ($d->channel) {
                'whatsapp' => $response['provider'] ?? null,
                'mail' => (string) config('mail.default'),
                'webpush' => 'webpush',
                default => 'app',
            },
            'provider_message_id' => $d->channel === 'whatsapp' ? ($response['id'] ?? null) : null,
            'notification_id' => $inApp ? $n->id : null,
        ]);
        $this->log($d, 'sent', null, match ($d->channel) {
            'webpush' => is_array($response) ? ($response['sent'] ?? 0).' perangkat' : null,
            'database' => $d->user?->email,
            default => $d->recipient,
        });

        return $d;
    }

    /** Kirim ulang pengiriman gagal/dibatalkan (satu percobaan tambahan). */
    public function retry(NotificationDelivery $d, ?User $actor = null): NotificationDelivery
    {
        abort_unless(in_array($d->status, ['failed', 'cancelled'], true), 422, 'Hanya pengiriman gagal/dibatalkan yang bisa dikirim ulang.');
        $before = ['status' => $d->status, 'attempts' => $d->attempts];
        $d->update(['status' => 'queued', 'max_attempts' => $d->attempts + 1, 'next_attempt_at' => null, 'failed_at' => null, 'locked_at' => null, 'scheduled_at' => null]);
        AuditLog::record('notification.retried', $d, $before, ['status' => 'queued'], $actor);

        return $this->process($d);
    }

    /** Batalkan pengiriman yang masih antre (mis. pengumuman terjadwal). */
    public function cancel(NotificationDelivery $d, ?User $actor = null): NotificationDelivery
    {
        abort_unless($d->status === 'queued', 422, 'Hanya pengiriman yang masih antre yang bisa dibatalkan.');
        $d->update(['status' => 'cancelled', 'locked_at' => null]);
        AuditLog::record('notification.cancelled', $d, ['status' => 'queued'], ['status' => 'cancelled'], $actor);

        return $d;
    }

    /**
     * Tanda terima dari penyedia (WhatsApp/email) — dipakai webhook penyedia nanti. Status hanya maju
     * (sent → delivered → read); "failed" setelah terkirim mencatat galat penyedia.
     */
    public function receipt(string $provider, string $messageId, string $status, ?Carbon $at = null, ?string $error = null): ?NotificationDelivery
    {
        $d = NotificationDelivery::query()->where('provider', $provider)->where('provider_message_id', $messageId)->first();
        if (! $d || ! in_array($status, ['delivered', 'read', 'failed'], true)) {
            return $d;
        }
        $at ??= now();
        if ($status === 'failed') {
            if (! in_array($d->status, ['delivered', 'read'], true)) {
                $d->update(['status' => 'failed', 'failed_at' => $at, 'last_error' => Str::limit($error ?? 'Ditolak penyedia', 1000, '')]);
                $this->log($d, 'failed', 'Tanda terima penyedia: '.($error ?? 'gagal'));
            }

            return $d;
        }
        if ((NotificationDelivery::PROGRESS[$status] ?? 0) > (NotificationDelivery::PROGRESS[$d->status] ?? 99)) {
            $d->update(['status' => $status, $status.'_at' => $at, 'delivered_at' => $d->delivered_at ?? $at]);
        }

        return $d;
    }

    /** Notifikasi aplikasi dibuka penerima → pengiriman "Dibaca". @param list<string> $notificationIds */
    public function markRead(array $notificationIds): int
    {
        if ($notificationIds === []) {
            return 0;
        }

        return NotificationDelivery::query()->where('channel', 'database')->whereIn('notification_id', $notificationIds)
            ->whereIn('status', ['sent', 'delivered'])->update(['status' => 'read', 'read_at' => now(), 'updated_at' => now()]);
    }

    /** Sinkron status "Dibaca" untuk notifikasi yang ditandai dibaca di luar API portal (mis. lonceng panel admin). */
    public function syncRead(): int
    {
        $read = DB::table('notifications')->whereNotNull('read_at')->select('id');

        return NotificationDelivery::query()->where('channel', 'database')->where('status', 'delivered')
            ->whereIn('notification_id', $read)->update(['status' => 'read', 'read_at' => now(), 'updated_at' => now()]);
    }

    private function notificationFor(NotificationDelivery $d): SystemNotification
    {
        $c = $d->content ?? [];

        return new SystemNotification(
            key: (string) ($c['key'] ?? $d->event?->key),
            category: (string) ($c['category'] ?? 'announcement'),
            title: (string) ($c['title'] ?? ''),
            body: (string) ($c['body'] ?? ''),
            url: $c['url'] ?? null,
            channels: [$d->channel],
            mailSubject: $c['mail_subject'] ?? null,
            mailBody: $c['mail_body'] ?? null,
            dedupeKey: $d->event?->dedupe_key,
            waBody: $c['wa_body'] ?? null,
            audience: $d->audience,
            recipientName: $d->recipient_name,
            deliveryId: $d->id,
        );
    }

    private function deliver(NotificationDelivery $d, SystemNotification $n): mixed
    {
        $user = $d->user;
        $notifiable = match (true) {
            $d->channel === 'whatsapp' => (new AnonymousNotifiable)->route('whatsapp', $d->recipient),
            $d->channel === 'mail' && (! $user || $user->email !== $d->recipient) => (new AnonymousNotifiable)->route('mail', [$d->recipient => $d->recipient_name]),
            default => $user,
        };
        if (! $notifiable) {
            throw new \RuntimeException('Penerima tidak memiliki akun untuk kanal '.$d->channel.'.');
        }
        $driver = match ($d->channel) {
            'webpush' => WebPushChannel::class,
            'whatsapp' => WhatsAppChannel::class,
            default => $d->channel,
        };

        $manager = app(ChannelManager::class);
        if (! $manager instanceof ChannelManager) {
            // Notification::fake() di tes: catat di fake, anggap terkirim
            $manager->sendNow($notifiable, $n, [$driver]);

            return null;
        }

        return $manager->driver($driver)->send($notifiable, $n);
    }

    private function failAttempt(NotificationDelivery $d, string $error): NotificationDelivery
    {
        $error = Str::limit($error, 1000, '');
        $this->log($d, 'failed', $error);
        if ($d->attempts >= $d->max_attempts) {
            $d->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => $error, 'locked_at' => null, 'next_attempt_at' => null]);
        } else {
            $backoff = (array) config('notifications.retry.backoff_minutes', [1, 5, 30, 120]);
            $minutes = (int) ($backoff[$d->attempts - 1] ?? end($backoff) ?: 5);
            $d->update(['status' => 'queued', 'last_error' => $error, 'locked_at' => null, 'next_attempt_at' => now()->addMinutes($minutes)]);
        }

        return $d;
    }

    private function log(NotificationDelivery $d, string $status, ?string $error = null, ?string $recipient = null): void
    {
        $user = $d->user;
        NotificationLog::query()->create([
            'delivery_id' => $d->id,
            'attempt' => $d->attempts ?: null,
            'user_id' => $d->user_id,
            'notifiable_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'notifiable_id' => $d->user_id,
            'notification' => (string) ($d->content['key'] ?? $d->event?->key),
            'category' => $d->content['category'] ?? null,
            'channel' => $d->channel,
            'recipient' => Str::limit((string) ($recipient ?? $d->recipient), 250, '') ?: null,
            'status' => $status,
            'subject' => Str::limit((string) ($d->content['title'] ?? ''), 250, ''),
            'error' => $error,
            'dedupe_key' => $d->event?->dedupe_key ? Str::limit($d->event->dedupe_key, 120, '') : null,
        ]);
    }
}
