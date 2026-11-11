<?php

use App\Models\ApiRequestLog;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\WebhookDelivery;
use App\Services\NotificationEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Minishlink\WebPush\VAPID;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat otomatis (PRD §5.12): kelas H-1, tenggat tugas < 24 jam, materi yang mulai tersedia hari ini. Aman diulang (dedupe).
Artisan::command('notifications:reminders', function (NotificationEvents $events) {
    $classes = $events->classReminders();
    $tasks = $events->assignmentDueReminders();
    $lessons = 0;
    Lesson::query()->where('is_published', true)->whereDate('available_from', today())->each(function (Lesson $l) use ($events, &$lessons): void {
        $events->lessonPublished($l);
        $lessons++;
    });
    $this->info("Pengingat kelas: $classes penerima, tenggat tugas: $tasks penerima, materi baru diperiksa: $lessons lesson.");
})->purpose('Kirim pengingat kelas H-1, tenggat tugas, dan materi yang mulai tersedia');

// Buat pasangan kunci VAPID untuk Web Push (salin ke .env: VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT)
Artisan::command('webpush:vapid', function () {
    $keys = VAPID::createVapidKeys();
    $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
    $this->line('VAPID_SUBJECT=mailto:admin@pusatpelatihan.id');
    $this->comment('Simpan kunci privat dengan aman. Mengganti kunci membuat semua langganan push lama tidak berlaku.');
})->purpose('Buat kunci VAPID untuk notifikasi push PWA');

Schedule::command('notifications:reminders')->hourly()->between('7:00', '20:00');

// Pangkas log permintaan API Publik, riwayat webhook & log notifikasi lama (API_LOG_RETENTION_DAYS, default 90 hari)
Schedule::command('model:prune', ['--model' => [ApiRequestLog::class, WebhookDelivery::class, NotificationLog::class]])->daily();
