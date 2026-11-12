<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Verifikasi email akun (Roadmap 5B, dasar identity matching §20.5). Tautan menuju halaman frontend /verifikasi-email
 * yang meneruskan parameter bertanda tangan (relatif, berlaku 48 jam) ke API.
 */
class VerifyEmailNotification extends Notification
{
    use Queueable;

    public const HOURS = 48;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi email akun PPTI - VMES')
            ->greeting("Halo {$notifiable->name},")
            ->line('Konfirmasikan bahwa alamat email ini milik Anda. Email terverifikasi membuat histori training Anda tersambung ke satu akun, termasuk training yang didaftarkan perusahaan.')
            ->action('Verifikasi email', self::url($notifiable))
            ->line('Tautan berlaku '.self::HOURS.' jam. Abaikan email ini bila Anda tidak membuat akun.')
            ->salutation('Salam, PPTI - VMES Training Center');
    }

    public static function url(object $user): string
    {
        $signed = URL::temporarySignedRoute('api.v1.auth.verify', now()->addHours(self::HOURS), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())], absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $q);
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');

        return $base.'/verifikasi-email?'.http_build_query(['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()), 'expires' => $q['expires'] ?? '', 'signature' => $q['signature'] ?? '']);
    }
}
