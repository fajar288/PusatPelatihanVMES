<?php

namespace App\Notifications;

use App\Models\TrainingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email perubahan status Corporate Training Request (Roadmap 5 §20.13) ke pengguna perusahaan (owner/HR/finance)
 * atau ke tim VMES. Mesin notifikasi terpadu (WA + email + jadwal) dibangun di tahap 5D.
 */
class TrainingRequestUpdated extends Notification
{
    use Queueable;

    public function __construct(public TrainingRequest $request, public string $from, public bool $forVmes = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->request->loadMissing('organization', 'package', 'schedule');
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $m = (new MailMessage)
            ->subject("[{$r->code}] {$r->label()} — {$r->package?->title}")
            ->greeting("Halo {$notifiable->name},")
            ->line("Permintaan training **{$r->code}** ({$r->package?->title}, {$r->seats} kursi) dari {$r->organization->name()} kini berstatus **{$r->label()}**.");

        match ($r->status) {
            'quoted' => $m->line('Penawaran: '.$rupiah($r->price_per_seat).' × '.$r->seats.' kursi'.($r->quote_discount > 0 ? ' − diskon '.$rupiah($r->quote_discount) : '').' = **'.$rupiah($r->quote_total).'**, berlaku sampai '.$r->quote_valid_until?->locale('id')->translatedFormat('j F Y').'.'),
            'approval' => $m->line('Penawaran sudah diterima dan menunggu persetujuan pengguna berwenang perusahaan.'),
            'paid' => $m->line('Pembayaran telah diverifikasi. VMES akan menetapkan jadwal batch.'),
            'scheduled' => $m->line('Batch: '.($r->schedule?->code ?? '-').'. Peserta yang sudah ditetapkan otomatis terdaftar di kelas.'),
            'cancelled' => $m->line('Alasan: '.($r->cancel_reason ?? '-')),
            default => $m,
        };

        return $this->forVmes
            ? $m->action('Buka di panel admin', rtrim((string) config('app.url'), '/').'/admin/training-requests')->salutation('Sistem ITMS PPTI')
            : $m->action('Buka Corporate Portal', "$base/corporate/{$r->organization->code}/permintaan/{$r->code}")->salutation('Salam, PPTI - VMES Training Center');
    }
}
