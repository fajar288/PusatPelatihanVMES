<?php

namespace App\Notifications;

use App\Enums\RegistrationStatus;
use App\Models\PaymentAccount;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email "status pendaftaran berubah" (PRD §5.12). */
class RegistrationStatusChanged extends Notification
{
    use Queueable;

    public function __construct(public Registration $registration, public RegistrationStatus $from) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registration->loadMissing('schedule.package', 'payments');
        $to = $r->status;
        $portal = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');
        $m = (new MailMessage)
            ->subject("Status pendaftaran {$r->registration_number}: {$to->label()}")
            ->greeting("Halo {$r->name},")
            ->line("Status pendaftaran **{$r->registration_number}** ({$r->schedule?->package?->title}) berubah dari *{$this->from->label()}* menjadi **{$to->label()}**.");

        if ($r->status_note) {
            $m->line('Catatan: '.$r->status_note);
        }

        match ($to) {
            RegistrationStatus::WaitingPayment => $this->paymentLines($m, $r),
            RegistrationStatus::PaymentVerification => $m->line('Bukti pembayaran Anda sedang diverifikasi tim finance.'),
            RegistrationStatus::Confirmed => $m->line('Kursi Anda telah dikonfirmasi. Undangan kelas dan akses materi akan dikirim sebelum kelas dimulai.'),
            RegistrationStatus::Rejected => $m->line('Mohon maaf, pendaftaran tidak dapat kami proses. Hubungi kami bila membutuhkan penjelasan.'),
            RegistrationStatus::Cancelled => $m->line('Pendaftaran telah dibatalkan.'),
            default => $m,
        };

        return $m->action('Lihat Pendaftaran', "$portal/portal/pendaftaran/{$r->registration_number}")
            ->salutation('Salam, PPTI - VMES Training Center');
    }

    /** Instruksi pembayaran manual: nominal, rekening/VA aktif (menu admin Rekening pembayaran), jatuh tempo. */
    private function paymentLines(MailMessage $m, Registration $r): MailMessage
    {
        $amount = 'Rp '.number_format((float) ($r->payments->first()?->amount ?? $r->total), 0, ',', '.');
        $accounts = PaymentAccount::query()->shown()->get();

        if ($accounts->isEmpty()) {
            $m->line("Silakan lakukan pembayaran sebesar **{$amount}** ke rekening resmi PT. PPTI - VMES Training Center, lalu unggah bukti transfer di portal member.");
        } else {
            $m->line("Silakan lakukan pembayaran sebesar **{$amount}** ke salah satu rekening resmi berikut:");
            foreach ($accounts as $a) {
                $m->line('- '.$a->summary());
            }
            $m->line('Setelah membayar, unggah bukti transfer di portal member.');
        }

        if ($due = $r->payments->first()?->due_date) {
            $m->line('Jatuh tempo: '.$due->locale('id')->translatedFormat('j F Y'));
        }

        return $m;
    }
}
