<?php

namespace App\Notifications;

use App\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email ke pengunggah bukti (member / finance perusahaan) bila bukti transfer ditolak finance VMES dan tidak ada email
 * perubahan status pendaftaran yang sudah memuat alasannya (mis. invoice training request atau termin berikutnya).
 */
class PaymentTransactionRejected extends Notification
{
    use Queueable;

    public function __construct(public PaymentTransaction $transaction) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tx = $this->transaction;
        $invoice = $tx->invoice;
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');
        $url = $invoice->organization_id && $invoice->training_request_id
            ? "$base/corporate/{$invoice->organization?->code}/keuangan/{$invoice->invoice_number}"
            : "$base/portal/pembayaran";

        return (new MailMessage)
            ->subject("Bukti pembayaran {$invoice->invoice_number} ditolak")
            ->greeting("Halo {$notifiable->name},")
            ->line("Bukti pembayaran {$tx->code} untuk invoice **{$invoice->invoice_number}** belum dapat kami verifikasi.")
            ->line('Alasan: '.($tx->review_note ?: '-'))
            ->line('Silakan unggah bukti transfer yang benar atau hubungi tim finance VMES.')
            ->action('Lihat tagihan', $url)
            ->salutation('Salam, PPTI - VMES Training Center');
    }
}
