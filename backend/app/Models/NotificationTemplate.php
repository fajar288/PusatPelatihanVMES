<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template pesan notifikasi berversi (PRD §5.12, Roadmap 5 §6.1). Satu template per (peristiwa × audiens):
 * peserta, admin perusahaan, trainer, admin VMES. Placeholder `{var}` diisi saat dikirim.
 * Setiap perubahan isi/kanal menaikkan `version` dan menyimpan salinan di `notification_template_versions`;
 * setiap pengiriman mencatat versi yang dipakai.
 *
 * Email transaksional peserta (pendaftaran diterima/berubah status, perubahan jadwal, sertifikat) tetap dikirim kelas
 * notifikasi khusus yang memuat rincian invoice/rekening, sehingga template peserta untuk peristiwa itu tidak memakai kanal email.
 */
class NotificationTemplate extends Model
{
    public const CATEGORIES = [
        'registration' => ['Pendaftaran & pembayaran', 'Status pendaftaran, tagihan, dan verifikasi pembayaran.'],
        'schedule' => ['Jadwal & pengingat kelas', 'Perubahan jadwal dan pengingat H-7 & H-1 sebelum kelas dimulai.'],
        'learning' => ['Materi, tugas & hasil', 'Materi baru, tenggat tugas, nilai, progres berisiko, kelulusan, dan hasil penilaian kompetensi.'],
        'certificate' => ['Sertifikat', 'Sertifikat terbit dan siap diunduh.'],
        'announcement' => ['Pengumuman', 'Informasi penting dari PPTI untuk peserta.'],
        'corporate' => ['Peserta disponsori perusahaan', 'Untuk pengguna perusahaan: pendaftaran, pembayaran, jadwal, progres berisiko, kelulusan & sertifikat peserta yang disponsori.'],
        'teaching' => ['Kelas yang saya ajar', 'Untuk trainer: pengingat H-7/H-1 dan perubahan jadwal kelas yang diajar.'],
        'operations' => ['Operasional admin', 'Untuk staf VMES: pendaftaran baru, pembayaran, peserta berisiko, kelulusan & sertifikat.'],
    ];

    public const CHANNELS = ['database' => 'Aplikasi (portal)', 'mail' => 'Email', 'webpush' => 'Push (perangkat)', 'whatsapp' => 'WhatsApp'];

    public const AUDIENCES = ['participant' => 'Peserta', 'corporate' => 'Admin perusahaan', 'trainer' => 'Trainer', 'admin' => 'Admin VMES'];

    /** Kolom isi yang diversikan. */
    public const VERSIONED = ['name', 'title', 'body', 'mail_subject', 'mail_body', 'wa_body', 'channels', 'is_active'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['channels' => 'array', 'placeholders' => 'array', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (NotificationTemplate $t): void {
            if (! $t->exists) {
                $t->version ??= 1;
                $t->is_active ??= true;
                $t->updated_by ??= auth()->id();
            } elseif ($t->isDirty(self::VERSIONED)) {
                $t->version = (int) $t->getOriginal('version') + 1;
                $t->updated_by = auth()->id();
            }
        });
        static::saved(function (NotificationTemplate $t): void {
            if ($t->wasRecentlyCreated || $t->wasChanged('version')) {
                $t->versions()->firstOrCreate(['version' => $t->version], $t->only(self::VERSIONED) + ['created_by' => auth()->id()]);
            }
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
    }

    /** Pulihkan isi versi lama → menjadi versi baru (riwayat tidak pernah ditimpa). */
    public function restoreVersion(NotificationTemplateVersion $v): self
    {
        abort_unless($v->template_id === $this->id, 404);
        $this->update($v->only(self::VERSIONED));

        return $this->refresh();
    }

    /** @param array<string, scalar|null> $vars */
    public static function render(?string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        }

        return trim(preg_replace('/\{[a-z_]+\}/', '', strtr((string) $text, $map)) ?? '');
    }

    public static function findActive(string $key, string $audience = 'participant'): ?self
    {
        self::ensureDefaults();

        return self::query()->where('key', $key)->where('audience', $audience)->where('is_active', true)->first();
    }

    /** Buat template bawaan yang belum ada (idempoten; template yang sudah diedit admin tidak ditimpa). */
    public static function ensureDefaults(): void
    {
        static $done = false;
        if ($done && ! app()->runningUnitTests()) {
            return;
        }
        $existing = self::query()->get(['key', 'audience'])->map(fn (self $t) => $t->audience.':'.$t->key)->all();
        foreach (self::defaults() as $t) {
            if (! in_array($t['audience'].':'.$t['key'], $existing, true)) {
                self::query()->create($t);
            }
        }
        $done = true;
    }

    /** Label peristiwa untuk filter admin. @return array<string, string> */
    public static function eventLabels(): array
    {
        return collect(self::defaults())->unique('key')->mapWithKeys(fn (array $t) => [$t['key'] => $t['name']])->all();
    }

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        $app = ['database', 'webpush'];
        $all = ['database', 'webpush', 'mail'];
        $wa = ['database', 'webpush', 'whatsapp'];
        $allWa = ['database', 'webpush', 'mail', 'whatsapp'];
        $corp = ['database', 'mail'];
        $corpWa = ['database', 'mail', 'whatsapp'];
        $trainer = ['database', 'mail', 'whatsapp'];
        $admin = ['database'];
        $classVars = ['name', 'program', 'class', 'date', 'time', 'location', 'link'];
        $corpVars = ['name', 'organization', 'program', 'class', 'date', 'count', 'participants', 'link'];

        $p = fn (string $key, string $category, string $name, array $channels, array $fields) => ['key' => $key, 'audience' => 'participant', 'category' => $category, 'name' => $name, 'channels' => $channels] + $fields;
        $c = fn (string $key, string $name, array $channels, array $fields) => ['key' => $key, 'audience' => 'corporate', 'category' => 'corporate', 'name' => $name, 'channels' => $channels] + $fields;
        $t = fn (string $key, string $name, array $channels, array $fields) => ['key' => $key, 'audience' => 'trainer', 'category' => 'teaching', 'name' => $name, 'channels' => $channels] + $fields;
        $a = fn (string $key, string $name, array $fields) => ['key' => $key, 'audience' => 'admin', 'category' => 'operations', 'name' => $name, 'channels' => $admin] + $fields;

        return [
            // ---------------------------------------------------------------- Registration
            $p('registration.received', 'registration', 'Pendaftaran diterima', $wa, [
                'title' => 'Pendaftaran {number} diterima', 'body' => 'Pendaftaran Anda untuk {program} ({class}) sedang kami proses.',
                'wa_body' => "Halo {name}, pendaftaran *{number}* untuk {program} ({class}) sudah kami terima dan sedang diproses.\n\nPantau statusnya di portal: {link}",
                'placeholders' => ['name', 'number', 'program', 'class', 'link']]),
            $c('registration.received', 'Pendaftaran kolektif diterima', $corp, [
                'title' => 'Pendaftaran {number} untuk {organization} diterima', 'body' => 'Pendaftaran kolektif {program} ({class}) sedang diproses VMES.',
                'mail_subject' => 'Pendaftaran {number} diterima', 'placeholders' => ['name', 'organization', 'number', 'program', 'class', 'link']]),
            $a('registration.received', 'Pendaftaran baru (admin)', [
                'title' => 'Pendaftaran baru {number}', 'body' => '{applicant} mendaftar {program} ({class}).',
                'placeholders' => ['number', 'applicant', 'program', 'class']]),
            $p('registration.status_changed', 'registration', 'Status pendaftaran berubah', $wa, [
                'title' => 'Pendaftaran {number}: {status}', 'body' => '{program} - status berubah dari {previous} menjadi {status}. {note}',
                'wa_body' => "Halo {name}, status pendaftaran *{number}* ({program}) kini *{status}*. {note}\n\nDetail: {link}",
                'placeholders' => ['name', 'number', 'program', 'status', 'previous', 'note', 'link']]),
            $p('enrollment.created', 'registration', 'Didaftarkan perusahaan', $allWa, [
                'title' => 'Anda terdaftar di {program}', 'body' => '{organization} mendaftarkan Anda di kelas {class}, mulai {date}.',
                'mail_subject' => 'Anda terdaftar di {program} ({class})',
                'mail_body' => "**{organization}** mendaftarkan Anda sebagai peserta **{program}** ({class}).\n\nKelas dimulai **{date}**. Materi pra-kelas dan jadwal lengkap tersedia di portal.",
                'wa_body' => "Halo {name}, {organization} mendaftarkan Anda di *{program}* ({class}), mulai {date}.\n\nLihat jadwal & materi: {link}",
                'placeholders' => ['name', 'organization', 'program', 'class', 'date', 'link']]),
            $c('enrollment.created', 'Peserta disponsori terdaftar', $corp, [
                'title' => '{count} peserta terdaftar di {class}', 'body' => '{participants} terdaftar di {program} ({class}), mulai {date}.',
                'mail_subject' => 'Peserta terdaftar: {program} ({class})', 'placeholders' => $corpVars]),

            // ---------------------------------------------------------------- Payment Success
            $p('payment.success', 'registration', 'Pembayaran berhasil', $allWa, [
                'title' => 'Pembayaran {invoice} diterima', 'body' => 'Pembayaran {amount} untuk {program} sudah diverifikasi. Terima kasih.',
                'mail_subject' => 'Pembayaran {invoice} diterima',
                'mail_body' => "Pembayaran sebesar **{amount}** untuk invoice **{invoice}** ({program}) sudah kami verifikasi.\n\nTerima kasih. Bukti dan rincian invoice dapat dilihat di portal.",
                'wa_body' => "Halo {name}, pembayaran *{amount}* untuk invoice {invoice} ({program}) sudah kami verifikasi. Terima kasih.\n\nRincian: {link}",
                'placeholders' => ['name', 'invoice', 'amount', 'program', 'link']]),
            $c('payment.success', 'Pembayaran perusahaan berhasil', $corp, [
                'title' => 'Pembayaran invoice {invoice} diterima', 'body' => 'Pembayaran {amount} dari {organization} untuk {program} sudah diverifikasi VMES.',
                'mail_subject' => 'Pembayaran invoice {invoice} diterima', 'placeholders' => ['name', 'organization', 'invoice', 'amount', 'program', 'link']]),
            $a('payment.success', 'Invoice lunas (admin)', [
                'title' => 'Invoice {invoice} lunas', 'body' => '{payer} - {amount} ({program}). Diverifikasi oleh {verifier}.',
                'placeholders' => ['invoice', 'payer', 'amount', 'program', 'verifier']]),

            // ---------------------------------------------------------------- H-7 / H-1
            $p('class.reminder_h7', 'schedule', 'Pengingat kelas (H-7)', $allWa, [
                'title' => 'Kelas {program} dimulai 7 hari lagi', 'body' => '{class} - {date}, {time} di {location}.',
                'mail_subject' => 'Pengingat: kelas {program} dimulai {date}',
                'mail_body' => "Kelas **{program}** ({class}) dimulai dalam 7 hari, **{date}** pukul {time}.\n\nLokasi: {location}\n\nPastikan administrasi dan pembayaran sudah selesai, lalu cek materi pra-kelas di portal.",
                'wa_body' => "Halo {name}, kelas *{program}* ({class}) dimulai {date} pukul {time} di {location}.\n\nCek persiapan di portal: {link}",
                'placeholders' => $classVars]),
            $c('class.reminder_h7', 'Pengingat kelas H-7 (perusahaan)', $corp, [
                'title' => 'Kelas {program} dimulai {date}', 'body' => '{count} peserta {organization} terdaftar di {class}: {participants}.',
                'mail_subject' => 'Pengingat H-7: {program} ({class})', 'placeholders' => $corpVars]),
            $t('class.reminder_h7', 'Pengingat mengajar H-7', $trainer, [
                'title' => 'Anda mengajar {program} mulai {date}', 'body' => '{class} - {date}, {time} di {location}. {count} peserta terdaftar.',
                'mail_subject' => 'Pengingat mengajar: {program} ({class}) mulai {date}',
                'wa_body' => 'Halo {name}, Anda dijadwalkan mengajar *{program}* ({class}) mulai {date} pukul {time} di {location}. Peserta terdaftar: {count}.',
                'placeholders' => ['name', 'program', 'class', 'date', 'time', 'location', 'count', 'link']]),
            $p('class.reminder', 'schedule', 'Pengingat kelas (H-1)', $allWa, [
                'title' => 'Besok kelas {program} dimulai', 'body' => '{class} - {date}, {time} di {location}. Sampai jumpa!',
                'mail_subject' => 'Pengingat: kelas {program} dimulai besok',
                'mail_body' => "Kelas **{program}** ({class}) dimulai besok, **{date}** pukul {time}.\n\nLokasi: {location}\n\nSiapkan perangkat dan materi pendukung, lalu cek materi pra-kelas di portal.",
                'wa_body' => 'Halo {name}, kelas *{program}* ({class}) dimulai *besok*, {date} pukul {time} di {location}. Sampai jumpa!',
                'placeholders' => $classVars]),
            $c('class.reminder', 'Pengingat kelas H-1 (perusahaan)', $corp, [
                'title' => 'Besok kelas {program} dimulai', 'body' => '{count} peserta {organization} mengikuti {class} mulai {date}: {participants}.',
                'mail_subject' => 'Pengingat H-1: {program} ({class})', 'placeholders' => $corpVars]),
            $t('class.reminder', 'Pengingat mengajar H-1', $trainer, [
                'title' => 'Besok Anda mengajar {program}', 'body' => '{class} - {date}, {time} di {location}. {count} peserta terdaftar.',
                'mail_subject' => 'Pengingat mengajar besok: {program} ({class})',
                'wa_body' => 'Halo {name}, besok ({date}) pukul {time} Anda mengajar *{program}* ({class}) di {location}. Peserta: {count}.',
                'placeholders' => ['name', 'program', 'class', 'date', 'time', 'location', 'count', 'link']]),

            // ---------------------------------------------------------------- Schedule Change
            $p('schedule.changed', 'schedule', 'Perubahan jadwal kelas', $wa, [
                'title' => 'Perubahan jadwal {class}', 'body' => '{program}: {changes}. {reason}',
                'wa_body' => "Halo {name}, ada perubahan jadwal *{program}* ({class}): {changes}. {reason}\n\nDetail: {link}",
                'placeholders' => ['name', 'class', 'program', 'changes', 'reason', 'link']]),
            $c('schedule.changed', 'Perubahan jadwal (perusahaan)', $corpWa, [
                'title' => 'Perubahan jadwal {class}', 'body' => '{program}: {changes}. {reason} Peserta {organization}: {participants}.',
                'mail_subject' => 'Perubahan jadwal {program} ({class})',
                'wa_body' => "Perubahan jadwal *{program}* ({class}): {changes}. {reason}\n\nPeserta {organization}: {participants}.",
                'placeholders' => ['name', 'organization', 'class', 'program', 'changes', 'reason', 'count', 'participants', 'link']]),
            $t('schedule.changed', 'Perubahan jadwal (trainer)', $trainer, [
                'title' => 'Perubahan jadwal kelas {class}', 'body' => '{program}: {changes}. {reason}',
                'mail_subject' => 'Perubahan jadwal kelas yang Anda ajar: {class}',
                'wa_body' => 'Halo {name}, jadwal kelas *{program}* ({class}) yang Anda ajar berubah: {changes}. {reason}',
                'placeholders' => ['name', 'class', 'program', 'changes', 'reason', 'link']]),

            // ---------------------------------------------------------------- Progress Risk
            $p('progress.risk', 'learning', 'Progres belajar berisiko', $allWa, [
                'title' => 'Progres {program} perlu perhatian', 'body' => '{reasons}. Hubungi trainer bila butuh bantuan.',
                'mail_subject' => 'Progres belajar {program} perlu perhatian',
                'mail_body' => "Kami melihat progres Anda di **{program}** ({class}) tertinggal:\n\n{reasons}.\n\nYuk kejar materi yang tertinggal di portal. Hubungi trainer bila butuh bantuan.",
                'wa_body' => 'Halo {name}, progres Anda di *{program}* ({class}) perlu perhatian: {reasons}. Lanjutkan belajar: {link}',
                'placeholders' => ['name', 'program', 'class', 'reasons', 'link']]),
            $c('progress.risk', 'Peserta berisiko (perusahaan)', $corp, [
                'title' => 'Peserta berisiko tertinggal: {participant}', 'body' => '{participant} ({program}, {class}) - {reasons}.',
                'mail_subject' => 'Peserta berisiko tertinggal: {participant}', 'placeholders' => ['name', 'organization', 'participant', 'program', 'class', 'reasons', 'link']]),
            $a('progress.risk', 'Peserta berisiko (admin)', [
                'title' => '{count} peserta berisiko tertinggal', 'body' => '{participants}',
                'placeholders' => ['count', 'participants']]),

            // ---------------------------------------------------------------- Training Completed
            $p('training.completed', 'learning', 'Pelatihan selesai', $allWa, [
                'title' => 'Pelatihan {program}: {result}', 'body' => 'Kelas {class} selesai. {detail}',
                'mail_subject' => 'Hasil pelatihan {program}: {result}',
                'mail_body' => "Kelas **{program}** ({class}) telah selesai dengan hasil **{result}**.\n\n{detail}",
                'wa_body' => 'Halo {name}, pelatihan *{program}* ({class}) selesai dengan hasil *{result}*. {detail}',
                'placeholders' => ['name', 'program', 'class', 'result', 'score', 'detail', 'link']]),
            $c('training.completed', 'Peserta menyelesaikan pelatihan (perusahaan)', $corp, [
                'title' => '{participant} menyelesaikan {program}', 'body' => 'Hasil: {result}. {score}',
                'mail_subject' => 'Hasil pelatihan {participant}: {result}', 'placeholders' => ['name', 'organization', 'participant', 'program', 'class', 'result', 'score', 'link']]),
            $a('training.completed', 'Kelulusan ditetapkan (admin)', [
                'title' => 'Kelulusan: {participant} - {result}', 'body' => '{program} ({class}). {next}',
                'placeholders' => ['participant', 'program', 'class', 'result', 'next']]),

            // ---------------------------------------------------------------- Certificate Issued
            $p('certificate.issued', 'certificate', 'Sertifikat terbit', $wa, [
                'title' => 'Sertifikat {number} terbit', 'body' => 'Sertifikat {program} sudah dapat diunduh di portal.',
                'wa_body' => 'Selamat {name}! Sertifikat *{program}* nomor {number} sudah terbit. Unduh di portal: {link}',
                'placeholders' => ['name', 'number', 'program', 'link']]),
            $c('certificate.issued', 'Sertifikat peserta terbit (perusahaan)', $corp, [
                'title' => 'Sertifikat {participant} terbit', 'body' => 'Sertifikat {number} untuk {program} sudah terbit.',
                'mail_subject' => 'Sertifikat {participant} terbit', 'placeholders' => ['name', 'organization', 'participant', 'number', 'program', 'link']]),
            $a('certificate.issued', 'Sertifikat terbit (admin)', [
                'title' => 'Sertifikat {number} terbit', 'body' => '{participant} - {program}. Diterbitkan oleh {issuer}.',
                'placeholders' => ['number', 'participant', 'program', 'issuer']]),

            // ---------------------------------------------------------------- Pembelajaran & lainnya (peserta)
            $p('lesson.published', 'learning', 'Materi baru', $all, [
                'title' => 'Materi baru: {lesson}', 'body' => 'Materi baru tersedia di {course}.',
                'mail_subject' => 'Materi baru di {course}', 'placeholders' => ['name', 'lesson', 'course']]),
            $p('assignment.due', 'learning', 'Pengingat tenggat tugas', $all, [
                'title' => 'Tenggat tugas {lesson} dalam 24 jam', 'body' => '{course}: kumpulkan sebelum {due}.',
                'mail_subject' => 'Pengingat tenggat tugas {lesson}', 'placeholders' => ['name', 'lesson', 'course', 'due']]),
            $p('assignment.graded', 'learning', 'Tugas dinilai', $all, [
                'title' => 'Tugas {lesson} {result}', 'body' => '{course}. {score} {feedback}',
                'mail_subject' => 'Tugas {lesson} {result}', 'placeholders' => ['name', 'lesson', 'course', 'result', 'score', 'feedback']]),
            $p('assessment.validated', 'learning', 'Hasil penilaian kompetensi', $all, [
                'title' => 'Hasil penilaian kompetensi {program}', 'body' => 'Skor {score}% - {result}.',
                'mail_subject' => 'Hasil penilaian kompetensi {program}', 'placeholders' => ['name', 'program', 'class', 'score', 'result']]),
            $p('announcement', 'announcement', 'Pengumuman', $all, [
                'title' => '{title}', 'body' => '{message}', 'mail_subject' => '{title}', 'placeholders' => ['name', 'title', 'message']]),
            $p('push.test', 'announcement', 'Uji notifikasi push', ['webpush'], [
                'title' => 'Notifikasi push aktif', 'body' => 'Perangkat ini akan menerima notifikasi dari PPTI VMES.', 'placeholders' => ['name']]),
        ];
    }
}
