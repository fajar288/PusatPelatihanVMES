<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\SystemNotification;
use App\Support\Push\PushSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pusat notifikasi (PRD §5.12): pilih template, render placeholder, tentukan kanal sesuai preferensi pengguna,
 * kirim per kanal (gagal satu kanal tidak menggagalkan yang lain) dan catat hasilnya di `notification_logs`.
 */
class NotificationCenter
{
    public function __construct(private readonly PushSender $push) {}

    /**
     * @param  User|iterable<User>  $users
     * @param  array<string, scalar|null>  $vars
     * @param  string[]|null  $onlyChannels  paksa kanal tertentu (mis. uji push)
     * @return int jumlah pengguna yang diberi notifikasi
     */
    public function send(User|iterable $users, string $key, array $vars = [], ?string $url = null, ?string $dedupeKey = null, ?array $onlyChannels = null): int
    {
        $template = NotificationTemplate::findActive($key);
        if (! $template) {
            return 0;
        }
        $users = collect($users instanceof User ? [$users] : $users)->filter()->unique('id');
        $count = 0;

        foreach ($users as $user) {
            if ($dedupeKey && NotificationLog::query()->where('dedupe_key', $dedupeKey)->where('user_id', $user->id)->where('status', 'sent')->exists()) {
                continue;
            }
            $channels = $onlyChannels ?? $this->channelsFor($user, $template);
            if ($channels === []) {
                continue;
            }
            $v = ['name' => $user->name] + $vars;
            $n = new SystemNotification(
                key: $template->key,
                category: $template->category,
                title: Str::limit(NotificationTemplate::render($template->title, $v), 180),
                body: Str::limit(preg_replace('/\s+/', ' ', NotificationTemplate::render($template->body, $v)), 480),
                url: $url,
                channels: $channels,
                mailSubject: $template->mail_subject ? NotificationTemplate::render($template->mail_subject, $v) : null,
                mailBody: $template->mail_body ? NotificationTemplate::render($template->mail_body, $v) : null,
                dedupeKey: $dedupeKey,
            );

            $any = false;
            foreach ($channels as $channel) {
                try {
                    // sukses dicatat listener LogSentNotification (event NotificationSent)
                    $user->notifyNow($n, [$channel === 'webpush' ? WebPushChannel::class : $channel]);
                    $any = true;
                } catch (Throwable $e) {
                    NotificationLog::query()->create([
                        'user_id' => $user->id, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                        'notification' => $template->key, 'category' => $template->category, 'channel' => $channel,
                        'recipient' => $channel === 'mail' ? $user->email : 'user#'.$user->id, 'status' => 'failed',
                        'subject' => $n->title, 'error' => Str::limit($e->getMessage(), 1000, ''), 'dedupe_key' => $dedupeKey,
                    ]);
                }
            }
            $count += $any ? 1 : 0;
        }

        return $count;
    }

    /** Kanal efektif: aplikasi selalu (bila ada di template); email & push mengikuti preferensi kategori. @return string[] */
    public function channelsFor(User $user, NotificationTemplate $template): array
    {
        $pref = $this->preferences($user)[$template->category] ?? ['mail' => true, 'push' => true];
        $channels = [];
        if ($template->hasChannel('database')) {
            $channels[] = 'database';
        }
        $emailAllowed = ($user->member?->communication_pref['email'] ?? true) !== false;
        if ($template->hasChannel('mail') && $pref['mail'] && $emailAllowed && filled($user->email)) {
            $channels[] = 'mail';
        }
        if ($template->hasChannel('webpush') && $pref['push'] && $this->push->configured() && $user->pushSubscriptions()->exists()) {
            $channels[] = 'webpush';
        }

        return $channels;
    }

    /** @return array<string, array{mail: bool, push: bool}> */
    public function preferences(User $user): array
    {
        $saved = $user->notificationPreferences()->get()->keyBy('category');
        $out = [];
        foreach (array_keys(NotificationTemplate::CATEGORIES) as $cat) {
            $p = $saved->get($cat);
            $out[$cat] = ['mail' => $p ? (bool) $p->mail : true, 'push' => $p ? (bool) $p->push : true];
        }

        return $out;
    }

    /** @param array<string, array{mail?: bool, push?: bool}> $prefs */
    public function savePreferences(User $user, array $prefs): array
    {
        foreach ($prefs as $cat => $p) {
            if (! array_key_exists($cat, NotificationTemplate::CATEGORIES)) {
                continue;
            }
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'category' => $cat],
                ['mail' => (bool) ($p['mail'] ?? true), 'push' => (bool) ($p['push'] ?? true)],
            );
        }

        return $this->preferences($user);
    }

    // ------------------------------------------------------------------ penerima

    /** Akun pemilik pendaftaran: akun pendaftar, akun member terkait, atau akun ber-email sama. @return Collection<int, User> */
    public static function usersForRegistration(Registration $r): Collection
    {
        $ids = array_filter([$r->user_id, $r->member?->user_id]);

        return User::query()->where(fn ($q) => $q->whereIn('id', $ids ?: [0])->orWhere('email', $r->email))->get();
    }

    /**
     * Akun peserta sebuah enrollment: member peserta, akun ber-email peserta, atau (pendaftaran individu) pemilik pendaftaran.
     * PIC pendaftaran kolektif tidak menerima notifikasi belajar tiap peserta.
     *
     * @return Collection<int, User>
     */
    public static function usersForEnrollment(Enrollment $e): Collection
    {
        $e->loadMissing('member', 'registration');
        $ids = array_filter([$e->member?->user_id]);
        $emails = array_filter([$e->participant_email]);
        if ($e->registration && $e->registration_participant_id === null) {
            $ids = array_merge($ids, array_filter([$e->registration->user_id]));
            $emails[] = $e->registration->email;
        }

        return User::query()->where(fn ($q) => $q->whereIn('id', $ids ?: [0])->orWhereIn('email', $emails ?: ['']))->get()->unique('id')->values();
    }

    /** @param iterable<Enrollment> $enrollments @return Collection<int, User> */
    public static function usersForEnrollments(iterable $enrollments): Collection
    {
        return collect($enrollments)->flatMap(fn (Enrollment $e) => self::usersForEnrollment($e))->unique('id')->values();
    }
}
