<?php

namespace App\Notifications;

use App\Models\ParticipantInvitation;
use App\Support\IdDate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email undangan peserta dari perusahaan (Roadmap 5 §20.4) — tautan /undangan/{token} di frontend. */
class ParticipantInvited extends Notification
{
    use Queueable;

    public function __construct(public ParticipantInvitation $invitation, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inv = $this->invitation->loadMissing('request.package', 'request.schedule', 'organization');
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');
        $schedule = $inv->request->schedule;

        return (new MailMessage)
            ->subject("Undangan training {$inv->request->package?->title} dari {$inv->organization->name()}")
            ->greeting('Halo'.($inv->name ? " {$inv->name}" : '').',')
            ->line("{$inv->organization->name()} menugaskan Anda mengikuti training **{$inv->request->package?->title}** di PPTI - VMES Training Center.")
            ->when($schedule, fn (MailMessage $m) => $m->line('Jadwal: '.IdDate::long($schedule->start_date).' – '.IdDate::long($schedule->end_date).'.'))
            ->line('Masuk atau buat akun dengan email ini, lalu terima undangan. Anda tetap memiliki satu akun peserta untuk seluruh histori training Anda; perusahaan hanya melihat progres training yang mereka sponsori.')
            ->action('Terima undangan', "$base/undangan/{$this->token}")
            ->line('Tautan berlaku sampai '.IdDate::long($inv->expires_at).'. Abaikan email ini bila Anda tidak mengenal pengirimnya.')
            ->salutation('Salam, PPTI - VMES Training Center');
    }
}
