<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email "pendaftaran diterima" ke pendaftar (PRD §5.12). */
class RegistrationReceived extends Notification
{
    use Queueable;

    public function __construct(public Registration $registration) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registration->loadMissing('schedule.package');
        $s = $r->schedule;
        $portal = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');

        return (new MailMessage)
            ->subject("Pendaftaran diterima — {$r->registration_number}")
            ->greeting("Halo {$r->name},")
            ->line("Terima kasih, pendaftaran Anda telah kami terima dengan nomor registrasi **{$r->registration_number}**.")
            ->line('Program: '.($s?->package?->title ?? $s?->title).' ('.$s?->code.')')
            ->line('Jadwal: '.$s?->start_date?->locale('id')->translatedFormat('j F Y').' – '.$s?->end_date?->locale('id')->translatedFormat('j F Y').', '.$s?->city.' ('.$s?->mode.')')
            ->when($r->total !== null, fn (MailMessage $m) => $m->line('Estimasi biaya: Rp '.number_format((float) $r->total, 0, ',', '.')))
            ->line('Tim admin akan meninjau pendaftaran dan mengirim rincian pembayaran dalam 1 hari kerja. Status pendaftaran dapat dipantau di portal member.')
            ->action('Buka Portal Member', "$portal/portal/pendaftaran/{$r->registration_number}")
            ->line('Jika Anda tidak merasa mendaftar, abaikan email ini.')
            ->salutation('Salam, PPTI - VMES Training Center');
    }
}
