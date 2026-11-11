<?php

namespace App\Notifications;

use App\Models\Certificate;
use App\Support\IdDate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email "sertifikat terbit" ke penerima (PRD §5.10, §5.12): nomor, tautan unduh di portal, tautan verifikasi. */
class CertificateIssued extends Notification
{
    use Queueable;

    public function __construct(public Certificate $certificate) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->certificate;
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');

        return (new MailMessage)
            ->subject("Sertifikat Anda telah terbit — {$c->program_title}")
            ->greeting("Halo {$c->recipient_name},")
            ->line("Selamat! {$c->typeLabel()} Anda untuk program **{$c->program_title}** (kelas {$c->class_code}) telah diterbitkan.")
            ->line("Nomor sertifikat: **{$c->number}**")
            ->line('Tanggal terbit: '.IdDate::long($c->issued_at).($c->valid_until ? ' • Berlaku hingga '.IdDate::long($c->valid_until) : ''))
            ->action('Unduh sertifikat di Portal Member', "$base/portal/sertifikat")
            ->line('Keaslian sertifikat dapat diperiksa siapa saja melalui tautan: '.$c->verificationUrl())
            ->line('Sebagai alumni, Anda dapat mendaftar program lanjutan dengan akun yang sama.')
            ->salutation('Salam, PPTI - VMES Training Center');
    }
}
