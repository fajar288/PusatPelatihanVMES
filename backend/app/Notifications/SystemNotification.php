<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Notifikasi dari template (Notification Engine): aplikasi (database), email, web push, dan WhatsApp.
 * Isi sudah dirender dari NotificationTemplate; `url` berupa path portal (mis. /portal/pendaftaran/REG-...)
 * atau path panel admin untuk audiens admin. Notifikasi audiens admin disimpan dalam format Filament
 * sehingga muncul di lonceng notifikasi panel admin ("Dashboard" pada PRD Roadmap 5 §6).
 */
class SystemNotification extends Notification
{
    /** @param string[] $channels database|mail|webpush|whatsapp */
    public function __construct(
        public string $key,
        public string $category,
        public string $title,
        public string $body,
        public ?string $url = null,
        public array $channels = ['database'],
        public ?string $mailSubject = null,
        public ?string $mailBody = null,
        public ?string $dedupeKey = null,
        public ?string $waBody = null,
        public string $audience = 'participant',
        public ?string $recipientName = null,
        public ?int $deliveryId = null,
    ) {
        // UUIDv7 berurutan waktu → urutan daftar notifikasi stabil walau created_at sama (detik)
        $this->id = (string) Str::uuid7();
    }

    public function via(object $notifiable): array
    {
        return array_map(fn (string $c) => match ($c) {
            'webpush' => WebPushChannel::class,
            'whatsapp' => WhatsAppChannel::class,
            default => $c,
        }, $this->channels);
    }

    /** Disimpan di kolom `notifications.type` agar mudah difilter. */
    public function databaseType(object $notifiable): string
    {
        return $this->audience === 'admin' ? 'admin:'.$this->key : $this->key;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $data = ['key' => $this->key, 'category' => $this->category, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
        if ($this->audience !== 'admin') {
            return $data;
        }
        $filament = FilamentNotification::make()->title($this->title)->body($this->body)->icon('heroicon-o-bell-alert')->info();
        if ($this->url) {
            $filament->actions([Action::make('open')->label('Buka')->url($this->url)->markAsRead()]);
        }

        return $data + $filament->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)
            ->subject($this->mailSubject ?: $this->title)
            ->greeting('Halo '.($notifiable->name ?? $this->recipientName ?? 'Bapak/Ibu').',');
        foreach (preg_split('/\R{2,}/', trim($this->mailBody ?: $this->body)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $m->line(trim($paragraph));
            }
        }
        if ($this->url) {
            $m->action($this->audience === 'corporate' ? 'Buka Corporate Portal' : 'Buka di portal', self::absoluteUrl($this->url));
        }

        return $m->line('Atur jenis notifikasi yang Anda terima di menu Notifikasi pada portal member.')
            ->salutation('Salam, Tim PPTI - VMES Training Center');
    }

    /** @return array<string, mixed> */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url ?? '/portal/notifikasi',
            'tag' => $this->key,
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/shortcut-96.png',
        ];
    }

    /** Teks WhatsApp: isi khusus WA bila ada, selain itu judul + isi singkat + tautan. */
    public function toWhatsApp(object $notifiable): string
    {
        if ($this->waBody) {
            return $this->waBody;
        }

        return trim('*'.$this->title."*\n".$this->body.($this->url ? "\n\n".self::absoluteUrl($this->url) : ''));
    }

    public static function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/').'/'.ltrim($path, '/');
    }
}
