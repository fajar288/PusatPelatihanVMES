<?php

namespace App\Notifications;

use App\Models\ScheduleChange;
use App\Models\TrainingSchedule;
use App\Support\IdDate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email "jadwal berubah" ke pendaftar aktif (PRD §5.4, §5.12). */
class ScheduleChanged extends Notification
{
    use Queueable;

    private const LABELS = [
        'start_date' => 'Tanggal mulai', 'end_date' => 'Tanggal selesai', 'start_time' => 'Jam mulai', 'end_time' => 'Jam selesai',
        'city' => 'Kota', 'location' => 'Lokasi', 'mode' => 'Metode', 'status' => 'Status kelas', 'trainer_name' => 'Trainer',
    ];

    public function __construct(public TrainingSchedule $schedule, public ScheduleChange $change) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->schedule->loadMissing('package');
        $name = $notifiable->name ?? 'Peserta';
        $m = (new MailMessage)
            ->subject("Perubahan jadwal kelas {$s->code} — ".($s->package?->title ?? $s->title))
            ->greeting("Halo {$name},")
            ->line('Ada perubahan pada kelas **'.($s->package?->title ?? $s->title)."** (kode {$s->code}):");

        foreach ($this->change->after ?? [] as $field => $to) {
            $from = $this->change->before[$field] ?? '-';
            $m->line('• '.(self::LABELS[$field] ?? $field).": {$from} → **{$to}**");
        }
        if ($this->change->reason) {
            $m->line('Alasan: '.$this->change->reason);
        }

        return $m
            ->line('Jadwal terkini: '.IdDate::long($s->start_date).' – '.IdDate::long($s->end_date).', '.$s->city.' ('.$s->mode.')'.($s->timeLabel() ? ', '.$s->timeLabel() : ''))
            ->line('Mohon maaf atas ketidaknyamanannya. Hubungi kami bila perubahan ini menyulitkan Anda.')
            ->salutation('Salam, PPTI - VMES Training Center');
    }
}
