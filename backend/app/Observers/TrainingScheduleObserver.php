<?php

namespace App\Observers;

use App\Enums\RegistrationStatus;
use App\Models\ScheduleChange;
use App\Models\TrainingSchedule;
use App\Notifications\ScheduleChanged;
use App\Services\NotificationEvents;

/**
 * Perubahan jadwal yang berdampak ke peserta (tanggal, jam, lokasi, metode, status) dicatat ke `schedule_changes`
 * beserta alasan (`$schedule->changeReason`, diisi form admin) dan diberitahukan ke pendaftar aktif.
 */
class TrainingScheduleObserver
{
    public const TRACKED = ['start_date', 'end_date', 'start_time', 'end_time', 'city', 'location', 'mode', 'status', 'trainer_name'];

    public function updated(TrainingSchedule $schedule): void
    {
        $changes = array_intersect_key($schedule->getChanges(), array_flip(self::TRACKED));
        if ($changes === []) {
            return;
        }
        $before = [];
        foreach (array_keys($changes) as $k) {
            // getAttribute() menerapkan cast (date → Carbon) sehingga tanggal tercatat sebagai Y-m-d, bukan Y-m-d H:i:s
            $before[$k] = $this->plain($schedule->getOriginal($k));
            $changes[$k] = $this->plain($schedule->getAttribute($k));
        }

        $change = ScheduleChange::query()->create([
            'schedule_id' => $schedule->id,
            'changed_by' => auth()->id(),
            'reason' => $schedule->changeReason,
            'before' => $before,
            'after' => $changes,
        ]);

        // beri tahu pendaftar yang masih aktif (bukan ditolak/dibatalkan)
        $registrations = $schedule->registrations()
            ->whereNotIn('status', [RegistrationStatus::Rejected->value, RegistrationStatus::Cancelled->value])
            ->get();
        foreach ($registrations as $r) {
            $r->notify(new ScheduleChanged($schedule, $change));
        }
        $change->update(['notified_count' => $registrations->count(), 'notified_at' => now()]);
        // notifikasi aplikasi/push untuk akun pendaftar & peserta (PRD §5.12)
        app(NotificationEvents::class)->scheduleChanged($schedule, $change);
    }

    private function plain(mixed $v): mixed
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ($v instanceof \BackedEnum ? $v->value : $v);
    }
}
