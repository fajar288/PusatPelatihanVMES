<?php

namespace App\Support\WhatsApp;

/** Helper nomor WhatsApp yang netral penyedia (dipakai kanal WA, Notification Center, dan pengaturan portal). */
final class PhoneNumber
{
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
