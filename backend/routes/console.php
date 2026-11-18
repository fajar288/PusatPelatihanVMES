<?php

use App\Models\AiInsight;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiRequestLog;
use App\Models\ChatLog;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Services\AiCorporateInsightService;
use App\Services\AiDemandForecastService;
use App\Services\AiEarlyWarningService;
use App\Services\AiEvaluationService;
use App\Services\AiFeedbackService;
use App\Services\AiInsightService;
use App\Services\AiReadiness;
use App\Services\IdentityMatchingService;
use App\Services\InvoiceService;
use App\Services\NotificationDispatcher;
use App\Services\NotificationEvents;
use App\Services\SponsorshipService;
use App\Services\SurveyService;
use App\Services\TrainingRequestService;
use App\Support\Ai\AiUseCases;
use App\Support\Enterprise\IdentityMatch;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat otomatis (PRD §5.12, Roadmap 5 §6): kelas H-7 & H-1 (peserta, perusahaan sponsor, trainer), tenggat tugas < 24 jam,
// materi yang mulai tersedia hari ini. Aman diulang (business event dibuat sekali per kelas per tanggal).
Artisan::command('notifications:reminders', function (NotificationEvents $events) {
    $h7 = $events->classRemindersH7();
    $classes = $events->classReminders();
    $tasks = $events->assignmentDueReminders();
    $lessons = 0;
    Lesson::query()->where('is_published', true)->whereDate('available_from', today())->each(function (Lesson $l) use ($events, &$lessons): void {
        $events->lessonPublished($l);
        $lessons++;
    });
    $this->info("Pengingat kelas H-7: $h7 penerima, H-1: $classes penerima, tenggat tugas: $tasks penerima, materi baru diperiksa: $lessons lesson.");
})->purpose('Kirim pengingat kelas H-7/H-1, tenggat tugas, dan materi yang mulai tersedia');

// Buat pasangan kunci VAPID untuk Web Push (salin ke .env: VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT)
Artisan::command('webpush:vapid', function () {
    $keys = VAPID::createVapidKeys();
    $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
    $this->line('VAPID_SUBJECT=mailto:admin@pusatpelatihan.id');
    $this->comment('Simpan kunci privat dengan aman. Mengganti kunci membuat semua langganan push lama tidak berlaku.');
})->purpose('Buat kunci VAPID untuk notifikasi push PWA');

Schedule::command('notifications:reminders')->hourly()->between('7:00', '20:00');

// Notification Engine (Roadmap 5 §6.1): kirim pengiriman terjadwal & retry yang jatuh tempo, sinkron status "Dibaca".
Artisan::command('notifications:dispatch {--limit=200}', function (NotificationDispatcher $dispatcher) {
    $sent = $dispatcher->processDue((int) $this->option('limit'));
    $read = $dispatcher->syncRead();
    $this->info("Pengiriman diproses: $sent. Ditandai dibaca: $read.");
})->purpose('Proses notifikasi terjadwal dan retry yang jatuh tempo');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping();

// Peringatan peserta berisiko tertinggal (Roadmap 5 §6 "Progress Risk") ke peserta, perusahaan sponsor & admin.
Artisan::command('notifications:risk', function (NotificationEvents $events) {
    $this->info('Peserta berisiko diperingatkan: '.$events->progressRisk());
})->purpose('Kirim peringatan progres berisiko (sekali per enrollment per periode)');

Schedule::command('notifications:risk')->dailyAt('08:00');

// Pangkas log permintaan API Publik, riwayat webhook & log notifikasi lama (API_LOG_RETENTION_DAYS, default 90 hari)
Schedule::command('model:prune', ['--model' => [ApiRequestLog::class, WebhookDelivery::class, NotificationLog::class, ChatLog::class, ApiIdempotencyKey::class]])->daily();

// Roadmap 5H: hapus token login yang sudah kedaluwarsa (SANCTUM_EXPIRATION) lebih dari 24 jam lalu.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();

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

// AI terukur: kesiapan data per use case (jumlah data riil dibanding ambang minimum di config/ai.php).
Artisan::command('ai:readiness {usecase? : nama use case, kosongkan untuk semua}', function (AiReadiness $readiness) {
    $key = $this->argument('usecase');
    if ($key !== null && ! AiUseCases::exists($key)) {
        $this->error("Use case tidak dikenal: $key. Pilihan: ".implode(', ', AiUseCases::keys()));

        return 1;
    }
    $reports = $key !== null ? [$readiness->report($key)] : $readiness->all();
    $rows = [];
    foreach ($reports as $r) {
        foreach ($r['checks'] ?: [['label' => '(tidak ada ambang data)', 'actual' => 0, 'required' => 0, 'ok' => true]] as $c) {
            $rows[] = [$r['key'], $c['label'], $c['actual'], $c['required'], $c['ok'] ? 'cukup' : 'belum cukup'];
        }
    }
    $this->table(['Use case', 'Data', 'Ada', 'Minimum', 'Status'], $rows);
    foreach ($reports as $r) {
        $this->line(sprintf('%s: %s', $r['key'], match (true) {
            $r['checks'] === [] => 'Tidak ada ambang data; nilai dari metrik pemakaian.',
            $r['ready'] => 'Data cukup untuk evaluasi.',
            default => 'Data belum cukup.',
        }));
    }

    return 0;
})->purpose('Bandingkan jumlah data riil dengan ambang minimum tiap use case AI');

// AI terukur: laporan evaluasi offline satu use case (metrik tata kelola + metrik khusus bila tersedia), disimpan sebagai JSON.
Artisan::command('ai:evaluate {usecase} {--json : cetak laporan lengkap sebagai JSON}', function (AiEvaluationService $evaluation) {
    $key = (string) $this->argument('usecase');
    if (! AiUseCases::exists($key)) {
        $this->error("Use case tidak dikenal: $key. Pilihan: ".implode(', ', AiUseCases::keys()));

        return 1;
    }
    $report = $evaluation->evaluate($key);
    $path = $evaluation->store($report);
    if ($this->option('json')) {
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }
    $g = $report['governance'];
    // Metrik berupa daftar (mis. rincian per deret) hanya diringkas di layar; isinya lengkap di laporan JSON.
    // Rasio (0..1) tampil sebagai persen; metrik lain (MAE, rata-rata) apa adanya; MAPE sudah dalam persen.
    $fmt = fn ($v, ?string $key = null) => match (true) {
        $v === null => '-',
        is_array($v) => count($v).' baris (lihat laporan JSON)',
        is_bool($v) => $v ? 'ya' : 'tidak',
        $key !== null && str_contains($key, 'mape') && is_numeric($v) => number_format((float) $v, 1).'%',
        is_float($v) && ($key === null || preg_match('/rate|coverage|precision|recall|f1|accuracy|ctr/', $key) === 1) => number_format($v * 100, 1).'%',
        is_float($v) => number_format($v, 2),
        default => (string) $v,
    };
    $this->info($report['label'].' ('.$key.')');
    $this->line('Status: '.($report['status']['available'] ? 'aktif' : 'tidak aktif ('.$report['status']['reason_label'].')'));
    $this->table(['Metrik', 'Nilai'], [
        ['Insight per status', collect($g['insights'])->map(fn ($n, $s) => (AiInsight::STATUSES[$s] ?? $s).': '.$n)->implode(', ') ?: 'belum ada'],
        ['Acceptance rate tinjauan', $fmt($g['acceptance_rate'])],
        ['Umpan balik membantu', $fmt($g['helpful_rate']).' dari '.$g['feedback_count']],
        ['Panggilan 30 hari', (string) $g['usage_30d']['calls']],
        ['Token 30 hari', (string) $g['usage_30d']['tokens']],
        ['Perkiraan biaya 30 hari (USD)', number_format($g['usage_30d']['cost_usd'], 4)],
        ['Penolakan model', $fmt($g['usage_30d']['refusal_rate'])],
        ['Galat', $fmt($g['usage_30d']['error_rate'])],
    ]);
    if ($report['specific']) {
        $this->table(['Metrik khusus', 'Nilai'], collect($report['specific']['metrics'])->map(fn ($v, $k) => [$k, $fmt($v, (string) $k)])->values()->all());
    }
    foreach ($report['notes'] as $note) {
        $this->warn($note);
    }
    $this->line("Laporan disimpan: storage/app/$path");

    return 0;
})->purpose('Buat laporan evaluasi offline satu use case AI');

// AI terukur: insight draft yang tidak ditinjau dalam AI_INSIGHT_TTL_DAYS hari dan insight lewat expires_at menjadi kedaluwarsa.
Artisan::command('ai:expire-insights', function (AiInsightService $insights) {
    $this->info('Insight AI kedaluwarsa: '.$insights->expireDue());
})->purpose('Tandai insight AI yang tidak ditinjau atau lewat masa berlaku sebagai kedaluwarsa');

Schedule::command('ai:expire-insights')->dailyAt('01:00');

// AI early warning: skor risiko harian peserta aktif (statistik, tanpa LLM). Tidak berjalan bila use case belum dinyalakan.
// Dijalankan sebelum notifications:risk (08:00); keduanya memakai kunci dedupe yang sama sehingga tidak ada peringatan ganda.
Artisan::command('ai:early-warning {--no-notify : hitung skor tanpa mengirim notifikasi}', function (AiEarlyWarningService $ew) {
    $r = $ew->run(! $this->option('no-notify'));
    if ($r['status'] !== 'ok') {
        $this->warn('Early warning belum tersedia: '.$r['reason'].'. Proses manual (aturan peserta berisiko) tetap berjalan.');

        return 0;
    }
    $this->info("Model {$r['model']}: {$r['scored']} peserta dinilai, {$r['flagged']} berisiko, {$r['notified']} notifikasi baru.");

    return 0;
})->purpose('Hitung skor risiko early warning untuk peserta aktif');

Schedule::command('ai:early-warning')->dailyAt('07:30');

// AI early warning: kalibrasi bobot dari kelas yang sudah selesai (holdout 30% terbaru, dibandingkan baseline aturan).
Artisan::command('ai:early-warning-calibrate {--force : jalankan walau data belum cukup (hanya untuk uji pipeline)}', function (AiEarlyWarningService $ew) {
    $r = $ew->calibrate(null, (bool) $this->option('force'));
    if ($r['status'] === 'insufficient_data') {
        $this->warn('Data belum cukup untuk kalibrasi. Jalankan php artisan ai:readiness early_warning.');

        return 0;
    }
    $fmt = fn ($v) => $v === null ? '-' : number_format($v * 100, 1).'%';
    $h = $r['holdout'];
    $this->table(['Holdout (data terbaru)', 'Precision', 'Recall', 'F1'], [
        ['Model baru '.$r['version'], $fmt($h['model']['precision']), $fmt($h['model']['recall']), $fmt($h['model']['f1'])],
        ['Bobot awal', $fmt($r['prior_on_holdout']['model']['precision']), $fmt($r['prior_on_holdout']['model']['recall']), $fmt($r['prior_on_holdout']['model']['f1'])],
        ['Baseline aturan', $fmt($h['baseline']['precision']), $fmt($h['baseline']['recall']), $fmt($h['baseline']['f1'])],
    ]);
    if ($r['forced']) {
        $this->warn('Data belum cukup: hasil ini hanya membuktikan pipeline kalibrasi berjalan.');
    }
    match ($r['holdout']['beats_baseline']) {
        true => $this->info("Versi {$r['version']} diaktifkan."),
        false => $this->warn("Versi {$r['version']} disimpan tetapi TIDAK diaktifkan karena kalah dari baseline aturan. Laporkan ke pemilik proyek."),
        null => $this->warn("Versi {$r['version']} disimpan tetapi TIDAK diaktifkan: data uji belum memuat peserta tidak lulus."),
    };

    return 0;
})->purpose('Kalibrasi bobot early warning dari kelas yang sudah selesai');

// Survei evaluasi pasca-kelas: undang peserta kelas yang sudah selesai (lewat Notification Engine). Aman diulang.
Artisan::command('surveys:invite', function (SurveyService $surveys) {
    $this->info('Undangan survei baru: '.$surveys->inviteDue());
})->purpose('Kirim undangan survei evaluasi ke peserta kelas yang sudah selesai');

Schedule::command('surveys:invite')->dailyAt('09:00');

// AI analisis umpan balik: proses Message Batches yang selesai lalu kirim komentar baru (klasifikasi). Tanpa worker
// permanen: dijalankan cron tiap jam. Tidak mengirim apa pun bila use case belum dinyalakan.
Artisan::command('ai:feedback-analyze {--limit=200}', function (AiFeedbackService $feedback) {
    $r = $feedback->analyze((int) $this->option('limit'));
    if ($r['status'] !== 'ok') {
        $this->warn('Analisis umpan balik belum tersedia: '.$r['reason'].'. Survei tetap dapat dibaca manual.');
    }
    $this->info("Batch selesai: {$r['ended']}, komentar terklasifikasi: {$r['classified']}, dikirim: {$r['submitted']}.");
})->purpose('Klasifikasi komentar survei lewat Claude Message Batches');

Schedule::command('ai:feedback-analyze')->hourly()->between('6:00', '22:00')->withoutOverlapping();

// AI ringkasan umpan balik per kelas (draf, wajib disetujui admin sebelum tampil ke trainer).
Artisan::command('ai:feedback-summarize', function (AiFeedbackService $feedback) {
    $this->info('Ringkasan kelas baru: '.$feedback->summarizeDue());
})->purpose('Susun ringkasan umpan balik per kelas untuk ditinjau admin');

Schedule::command('ai:feedback-summarize')->dailyAt('06:30');

// AI insight kompetensi per organisasi (draf, wajib disetujui admin VMES sebelum tampil di portal perusahaan).
Artisan::command('ai:corporate-insight {--org= : kode organisasi, kosongkan untuk semua yang punya target} {--force : buat walau data tidak berubah}', function (AiCorporateInsightService $insights) {
    if (! $insights->status()['available']) {
        $this->warn('Insight perusahaan belum tersedia: '.($insights->status()['reason'] ?? 'fitur mati').'. Laporan gap tanpa narasi tetap berjalan.');

        return 0;
    }
    if ($code = $this->option('org')) {
        $org = Organization::query()->where('code', strtoupper((string) $code))->first();
        if (! $org) {
            $this->error("Organisasi $code tidak ditemukan.");

            return 1;
        }
        $insight = $insights->generate($org, (bool) $this->option('force'));
        $this->info($insight ? "Draf insight #{$insight->id} dibuat untuk {$org->code}, menunggu persetujuan admin." : 'Tidak ada draf baru (data kurang, tidak berubah, atau model gagal).');

        return 0;
    }
    $this->info('Draf insight baru: '.$insights->generateDue());

    return 0;
})->purpose('Susun insight kompetensi per organisasi untuk ditinjau admin');

Schedule::command('ai:corporate-insight')->weeklyOn(1, '06:00');

// AI prakiraan permintaan (statistik, tanpa LLM): peserta terdaftar per bulan 3 bulan ke depan, tampil di Dashboard Eksekutif.
Artisan::command('ai:demand-forecast', function (AiDemandForecastService $forecast) {
    if (! $forecast->status()['available']) {
        $this->warn('Prakiraan permintaan belum tersedia: '.($forecast->status()['reason'] ?? 'fitur mati').'.');

        return 0;
    }
    $insight = $forecast->run();
    $this->info($insight
        ? 'Prakiraan disimpan (insight #'.$insight->id.', '.count($insight->output['series']).' lingkup, bulan '.implode(', ', $insight->output['months']).').'
        : 'Riwayat peserta belum cukup untuk prakiraan (minimal 4 bulan lengkap).');

    return 0;
})->purpose('Hitung prakiraan peserta terdaftar 3 bulan ke depan');

Schedule::command('ai:demand-forecast')->monthlyOn(1, '05:00');

// Pindahkan berkas pribadi/berbayar lama dari disk default (public) ke disk privat. Path di database tidak berubah,
// jadi aman dijalankan berulang; berkas yang sudah ada di disk privat dilewati.
Artisan::command('files:privatize {--dry-run : hanya tampilkan jumlah berkas yang akan dipindah}', function () {
    $from = Storage::disk((string) config('filesystems.default'));
    $to = PrivateFiles::disk();
    if (config('filesystems.default') === PrivateFiles::diskName()) {
        $this->warn('Disk default sama dengan disk privat; tidak ada yang dipindah.');

        return;
    }
    $moved = 0;
    foreach (PrivateFiles::DIRECTORIES as $dir) {
        $files = $from->allFiles($dir);
        $this->line(sprintf('%-22s %d berkas', $dir, count($files)));
        if ($this->option('dry-run')) {
            continue;
        }
        foreach ($files as $path) {
            if (! $to->exists($path)) {
                $stream = $from->readStream($path);
                $to->writeStream($path, $stream);
                is_resource($stream) && fclose($stream);
            }
            $from->delete($path);
            $moved++;
        }
    }
    $this->info($this->option('dry-run') ? 'Mode uji: tidak ada berkas yang dipindah.' : "$moved berkas dipindah ke disk privat.");
})->purpose('Pindahkan bukti bayar, tugas, logbook, dan materi LMS lama ke disk privat');
