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

    /** Normalisasi nomor Indonesia: 0812… / +62 812… / 62812… → 62812…; null bila tidak valid. */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $cc = (string) config('notifications.whatsapp.country_code', '62');
        if (str_starts_with($digits, '0')) {
            $digits = $cc.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = $cc.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}
