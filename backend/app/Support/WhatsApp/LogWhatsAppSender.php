<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Driver `log`: pesan WhatsApp hanya ditulis ke log aplikasi (tidak ada penyedia WA yang dipasang). */
class LogWhatsAppSender implements WhatsAppSender
{
    public function enabled(): bool
    {
        return (bool) config('notifications.whatsapp.enabled');
    }

    public function provider(): string
    {
        return 'log';
    }

    public function send(string $to, string $text): string
    {
        $id = 'log-'.Str::uuid7();
        $channel = config('notifications.whatsapp.log_channel');
        ($channel ? Log::channel($channel) : Log::getLogger())->info('[whatsapp:log] pesan ke '.$to, ['id' => $id, 'text' => $text]);

        return $id;
    }
}
