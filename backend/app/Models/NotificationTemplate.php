<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Template pesan notifikasi (PRD §5.12). Placeholder `{var}` diisi saat dikirim.
 * Email transaksional pendaftaran/sertifikat/perubahan jadwal tetap dikirim kelas notifikasi khusus (selalu aktif),
 * sehingga template-nya hanya memakai kanal aplikasi & push.
 */
class NotificationTemplate extends Model
{
    public const CATEGORIES = [
        'registration' => ['Pendaftaran & pembayaran', 'Status pendaftaran, tagihan, dan verifikasi pembayaran.'],
        'schedule' => ['Jadwal & pengingat kelas', 'Perubahan jadwal dan pengingat H-1 sebelum kelas dimulai.'],
        'learning' => ['Materi, tugas & hasil', 'Materi baru, tenggat tugas, nilai tugas, dan hasil penilaian kompetensi.'],
        'certificate' => ['Sertifikat', 'Sertifikat terbit dan siap diunduh.'],
        'announcement' => ['Pengumuman', 'Informasi penting dari PPTI untuk peserta.'],
    ];

    public const CHANNELS = ['database' => 'Aplikasi (portal)', 'mail' => 'Email', 'webpush' => 'Push (perangkat)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['channels' => 'array', 'placeholders' => 'array', 'is_active' => 'boolean'];
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
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

    public static function findActive(string $key): ?self
    {
        self::ensureDefaults();

        return self::query()->where('key', $key)->where('is_active', true)->first();
    }

    /** Buat template bawaan yang belum ada (idempoten; template yang sudah diedit admin tidak ditimpa). */
    public static function ensureDefaults(): void
    {
        static $done = false;
        if ($done && ! app()->runningUnitTests()) {
            return;
        }
        $existing = self::query()->pluck('key')->all();
        foreach (self::defaults() as $key => $t) {
            if (! in_array($key, $existing, true)) {
                self::query()->create(['key' => $key] + $t);
            }
        }
        $done = true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $app = ['database', 'webpush'];
        $all = ['database', 'webpush', 'mail'];

        return [
            'registration.received' => ['category' => 'registration', 'name' => 'Pendaftaran diterima', 'channels' => $app,
                'title' => 'Pendaftaran {number} diterima', 'body' => 'Pendaftaran Anda untuk {program} ({class}) sedang kami proses.',
                'placeholders' => ['name', 'number', 'program', 'class']],
            'registration.status_changed' => ['category' => 'registration', 'name' => 'Status pendaftaran berubah', 'channels' => $app,
                'title' => 'Pendaftaran {number}: {status}', 'body' => '{program} - status berubah dari {previous} menjadi {status}. {note}',
                'placeholders' => ['name', 'number', 'program', 'status', 'previous', 'note']],
            'schedule.changed' => ['category' => 'schedule', 'name' => 'Perubahan jadwal kelas', 'channels' => $app,
                'title' => 'Perubahan jadwal {class}', 'body' => '{program}: {changes}. {reason}',
                'placeholders' => ['name', 'class', 'program', 'changes', 'reason']],
            'class.reminder' => ['category' => 'schedule', 'name' => 'Pengingat kelas (H-1)', 'channels' => $all,
                'title' => 'Besok kelas {program} dimulai', 'body' => '{class} - {date}, {time} di {location}. Sampai jumpa!',
                'mail_subject' => 'Pengingat: kelas {program} dimulai besok',
                'mail_body' => "Kelas **{program}** ({class}) dimulai besok, **{date}** pukul {time}.\n\nLokasi: {location}\n\nSiapkan perangkat dan materi pendukung, lalu cek materi pra-kelas di portal.",
                'placeholders' => ['name', 'program', 'class', 'date', 'time', 'location']],
            'lesson.published' => ['category' => 'learning', 'name' => 'Materi baru', 'channels' => $all,
                'title' => 'Materi baru: {lesson}', 'body' => 'Materi baru tersedia di {course}.',
                'mail_subject' => 'Materi baru di {course}', 'placeholders' => ['name', 'lesson', 'course']],
            'assignment.due' => ['category' => 'learning', 'name' => 'Pengingat tenggat tugas', 'channels' => $all,
                'title' => 'Tenggat tugas {lesson} dalam 24 jam', 'body' => '{course}: kumpulkan sebelum {due}.',
                'mail_subject' => 'Pengingat tenggat tugas {lesson}', 'placeholders' => ['name', 'lesson', 'course', 'due']],
            'assignment.graded' => ['category' => 'learning', 'name' => 'Tugas dinilai', 'channels' => $all,
                'title' => 'Tugas {lesson} {result}', 'body' => '{course}. {score} {feedback}',
                'mail_subject' => 'Tugas {lesson} {result}', 'placeholders' => ['name', 'lesson', 'course', 'result', 'score', 'feedback']],
            'assessment.validated' => ['category' => 'learning', 'name' => 'Hasil penilaian kompetensi', 'channels' => $all,
                'title' => 'Hasil penilaian kompetensi {program}', 'body' => 'Skor {score}% - {result}.',
                'mail_subject' => 'Hasil penilaian kompetensi {program}', 'placeholders' => ['name', 'program', 'class', 'score', 'result']],
            'certificate.issued' => ['category' => 'certificate', 'name' => 'Sertifikat terbit', 'channels' => $app,
                'title' => 'Sertifikat {number} terbit', 'body' => 'Sertifikat {program} sudah dapat diunduh di portal.',
                'placeholders' => ['name', 'number', 'program']],
            'announcement' => ['category' => 'announcement', 'name' => 'Pengumuman', 'channels' => $all,
                'title' => '{title}', 'body' => '{message}', 'mail_subject' => '{title}', 'placeholders' => ['name', 'title', 'message']],
            'push.test' => ['category' => 'announcement', 'name' => 'Uji notifikasi push', 'channels' => ['webpush'],
                'title' => 'Notifikasi push aktif', 'body' => 'Perangkat ini akan menerima notifikasi dari PPTI VMES.', 'placeholders' => ['name']],
        ];
    }
}
