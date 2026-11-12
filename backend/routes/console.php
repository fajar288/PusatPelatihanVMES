<?php

use App\Models\ApiRequestLog;
use App\Models\ChatLog;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\WebhookDelivery;
use App\Services\IdentityMatchingService;
use App\Services\InvoiceService;
use App\Services\NotificationEvents;
use App\Services\SponsorshipService;
use App\Services\TrainingRequestService;
use App\Support\Enterprise\IdentityMatch;
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
Schedule::command('model:prune', ['--model' => [ApiRequestLog::class, WebhookDelivery::class, NotificationLog::class, ChatLog::class]])->daily();

// Roadmap 5 (5A): cocokkan peserta enrollment yang belum punya member ke Member yang ada (PRD Roadmap 5 §20.5).
// Hanya hasil yakin (email terverifikasi / nomor member) yang ditautkan; sisanya masuk Review identitas. Aman diulang.
Artisan::command('identity:backfill', function (IdentityMatchingService $identity) {
    $linked = 0;
    $queued = 0;
    Enrollment::query()->whereNull('member_id')->with(['schedule', 'sponsor', 'registration', 'registrationParticipant'])->orderBy('id')
        ->each(function (Enrollment $e) use ($identity, &$linked, &$queued): void {
            $identity->resolveEnrollment($e)->outcome === IdentityMatch::USE_EXISTING ? $linked++ : $queued++;
        });
    $this->info("Ditautkan otomatis: $linked enrollment. Masuk Review identitas: $queued.");
})->purpose('Cocokkan enrollment tanpa member ke Member yang ada; sisanya ke review queue');

// Terapkan transfer sponsor yang sudah disetujui dan tanggal efektifnya tiba (PRD Roadmap 5 §20.20).
Artisan::command('sponsorship:apply-due', function (SponsorshipService $sponsorship) {
    $this->info('Transfer sponsor diterapkan: '.$sponsorship->applyDue());
})->purpose('Terapkan transfer sponsor yang disetujui dengan tanggal efektif hari ini atau lebih awal');

Schedule::command('sponsorship:apply-due')->dailyAt('00:15');

// Roadmap 5B: Corporate Training Request mengikuti jadwal batch (SCHEDULED → IN_PROGRESS → COMPLETED).
Artisan::command('requests:sync-status', function (TrainingRequestService $requests) {
    $this->info('Permintaan training diperbarui: '.$requests->syncWithSchedules());
})->purpose('Sinkronkan status Corporate Training Request dengan jadwal batch');

Schedule::command('requests:sync-status')->dailyAt('00:30');

// Roadmap 5C: invoice belum dibayar yang lewat jatuh tempo → Expired (kecuali ada bukti menunggu verifikasi). Aman diulang.
Artisan::command('invoices:expire', function (InvoiceService $invoices) {
    $this->info('Invoice kedaluwarsa: '.$invoices->expireOverdue());
})->purpose('Tandai invoice yang lewat jatuh tempo tanpa pembayaran sebagai kedaluwarsa');

Schedule::command('invoices:expire')->dailyAt('00:45');
