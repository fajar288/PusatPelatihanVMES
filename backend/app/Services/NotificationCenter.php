<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Notify\Recipient;
use App\Support\Push\PushSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notification Engine (PRD §5.12 + Roadmap 5 §6/§6.1). Business event dicatat SEKALI di `notification_events`,
 * lalu engine menentukan penerima per scope (peserta, admin perusahaan, trainer, admin VMES), template berversi per
 * audiens, kanal (aplikasi, email, push, WhatsApp) sesuai preferensi, jadwal, lalu membuat satu `notification_deliveries`
 * per penerima × kanal. Pengiriman, retry, dan status dikerjakan NotificationDispatcher.
 */
class NotificationCenter
{
    public function __construct(
        private readonly PushSender $push,
        private readonly WhatsAppSender $whatsapp,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * Kirim template audiens peserta ke sekumpulan akun (dipakai pengumuman, uji push, notifikasi pembelajaran).
     *
     * @param  User|iterable<User>  $users
     * @param  array<string, scalar|null>  $vars
     * @param  string[]|null  $onlyChannels  paksa kanal tertentu (mis. uji push; tanpa retry)
     * @return int jumlah pengguna yang (akan) menerima notifikasi — tidak termasuk yang semua kanalnya gagal
     */
    public function send(User|iterable $users, string $key, array $vars = [], ?string $url = null, ?string $dedupeKey = null, ?array $onlyChannels = null, ?DateTimeInterface $scheduledAt = null): int
    {
        $recipients = collect($users instanceof User ? [$users] : $users)->filter()->unique('id')->map(fn (User $u) => Recipient::user($u));
        $result = $this->publish($key, $recipients, $vars, [
            'url' => $url, 'dedupe' => $dedupeKey, 'only_channels' => $onlyChannels,
            'max_attempts' => $onlyChannels ? 1 : null, 'scheduled_at' => $scheduledAt,
        ]);

        return $result['recipients'];
    }

    /**
     * Catat business event dan buat pengirimannya.
     *
     * @param  iterable<Recipient>  $recipients
     * @param  array<string, scalar|null>  $vars  variabel bersama semua penerima
     * @param  array{url?: ?string, urls?: array<string, string>, dedupe?: ?string, subject?: ?Model, actor?: ?User, scheduled_at?: ?DateTimeInterface, only_channels?: ?array, max_attempts?: ?int}  $opts
     * @return array{event: ?NotificationEvent, recipients: int, deliveries: int}
     */
    public function publish(string $key, iterable $recipients, array $vars = [], array $opts = []): array
    {
        $recipients = collect($recipients)->filter()->unique(fn (Recipient $r) => $r->key())->values();
        if ($recipients->isEmpty()) {
            return ['event' => null, 'recipients' => 0, 'deliveries' => 0];
        }
        $scheduledAt = isset($opts['scheduled_at']) && $opts['scheduled_at'] > now() ? $opts['scheduled_at'] : null;
        $subject = $opts['subject'] ?? null;
        $attributes = [
            'key' => $key,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'payload' => ['vars' => $vars, 'url' => $opts['url'] ?? null],
            'actor_id' => array_key_exists('actor', $opts) ? $opts['actor']?->id : auth()->id(),
            'scheduled_at' => $scheduledAt,
        ];
        $dedupe = $opts['dedupe'] ?? null;
        $event = $dedupe
            ? NotificationEvent::query()->createOrFirst(['dedupe_key' => Str::limit($dedupe, 160, '')], $attributes)
            : NotificationEvent::query()->create($attributes);
        $already = $event->wasRecentlyCreated ? [] : $event->deliveries()->distinct()->pluck('recipient_key')->all();

        $templates = [];
        $ids = [];
        $count = 0;
        foreach ($recipients as $r) {
            if (in_array($r->key(), $already, true)) {
                continue;   // event sama, orang sama → tidak dikirim ulang
            }
            $template = $templates[$r->audience] ??= NotificationTemplate::findActive($key, $r->audience) ?? false;
            if (! $template) {
                continue;
            }
            $channels = array_values(array_intersect($opts['only_channels'] ?? $this->channelsFor($r, $template), array_keys(NotificationTemplate::CHANNELS)));
            if ($opts['only_channels'] ?? null) {
                $channels = array_values(array_filter($channels, fn ($c) => $c !== 'database' || $r->user));
            }
            if ($channels === []) {
                continue;
            }
            $content = $this->render($template, $r, $vars, $r->url ?? ($opts['urls'][$r->audience] ?? null) ?? ($opts['url'] ?? null));
            foreach ($channels as $channel) {
                $ids[] = NotificationDelivery::query()->create([
                    'event_id' => $event->id,
                    'template_id' => $template->id,
                    'template_version' => $template->version,
                    'audience' => $r->audience,
                    'channel' => $channel,
                    'user_id' => $r->user?->id,
                    'recipient_key' => $r->key(),
                    'recipient' => Str::limit((string) $this->address($r, $channel), 250, ''),
                    'recipient_name' => Str::limit($r->displayName(), 250, ''),
                    'organization_id' => $r->organizationId,
                    'content' => $content,
                    'status' => 'queued',
                    'max_attempts' => max(1, (int) ($opts['max_attempts'] ?? config('notifications.retry.max_attempts', 4))),
                    'scheduled_at' => $scheduledAt,
                ])->id;
            }
            $count++;
        }
        if ($ids) {
            $event->increment('deliveries_count', count($ids));
        }

        if ($ids && ! $scheduledAt && config('notifications.inline', true)) {
            // setelah transaksi bisnis selesai (langsung bila tidak di dalam transaksi)
            DB::afterCommit(fn () => $this->dispatcher->processIds($ids));
            $failedRecipients = NotificationDelivery::query()->whereIn('id', $ids)->get()->groupBy('recipient_key')
                ->filter(fn (Collection $ds) => $ds->every(fn (NotificationDelivery $d) => $d->status === 'failed'))->count();
            $count -= $failedRecipients;
        }

        return ['event' => $event, 'recipients' => $count, 'deliveries' => count($ids)];
    }

    /**
     * Kanal efektif untuk satu penerima: aplikasi selalu (akun), email/push/WhatsApp mengikuti template, preferensi kategori,
     * preferensi komunikasi profil, dan ketersediaan alamat.
     *
     * @return string[]
     */
    public function channelsFor(Recipient|User $recipient, NotificationTemplate $template): array
    {
        $r = $recipient instanceof User ? Recipient::user($recipient) : $recipient;
        $user = $r->user;
        $pref = $user ? ($this->preferences($user)[$template->category] ?? ['mail' => true, 'push' => true, 'whatsapp' => true]) : ['mail' => true, 'push' => false, 'whatsapp' => true];
        $comm = $user?->member?->communication_pref ?? [];
        $channels = [];
        if ($template->hasChannel('database') && $user) {
            $channels[] = 'database';
        }
        if ($template->hasChannel('mail') && $pref['mail'] && ($comm['email'] ?? true) !== false && filled($r->email ?? $user?->email)) {
            $channels[] = 'mail';
        }
        if ($template->hasChannel('webpush') && $user && $pref['push'] && $this->push->configured() && $user->pushSubscriptions()->exists()) {
            $channels[] = 'webpush';
        }
        if ($template->hasChannel('whatsapp') && $this->whatsapp->enabled() && $pref['whatsapp'] && ($comm['whatsapp'] ?? true) !== false
            && $this->address($r, 'whatsapp')) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    /** @return array<string, array{mail: bool, push: bool, whatsapp: bool}> */
    public function preferences(User $user): array
    {
        $saved = $user->notificationPreferences()->get()->keyBy('category');
        $out = [];
        foreach (array_keys(NotificationTemplate::CATEGORIES) as $cat) {
            $p = $saved->get($cat);
            $out[$cat] = ['mail' => $p ? (bool) $p->mail : true, 'push' => $p ? (bool) $p->push : true, 'whatsapp' => $p ? (bool) $p->whatsapp : true];
        }

        return $out;
    }

    /** @param array<string, array{mail?: bool, push?: bool, whatsapp?: bool}> $prefs */
    public function savePreferences(User $user, array $prefs): array
    {
        $current = $this->preferences($user);
        foreach ($prefs as $cat => $p) {
            if (! array_key_exists($cat, NotificationTemplate::CATEGORIES)) {
                continue;
            }
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'category' => $cat],
                [
                    'mail' => (bool) ($p['mail'] ?? $current[$cat]['mail']),
                    'push' => (bool) ($p['push'] ?? $current[$cat]['push']),
                    'whatsapp' => (bool) ($p['whatsapp'] ?? $current[$cat]['whatsapp']),
                ],
            );
        }

        return $this->preferences($user);
    }

    /** Alamat tujuan per kanal. */
    public function address(Recipient $r, string $channel): ?string
    {
        return match ($channel) {
            'mail' => $r->email ?? $r->user?->email,
            'whatsapp' => LogWhatsAppSender::normalize($r->phone ?? $r->user?->whatsappNumber()),
            default => $r->user ? 'user#'.$r->user->id : null,
        };
    }

    /** @return array<string, string|null> */
    private function render(NotificationTemplate $t, Recipient $r, array $vars, ?string $url): array
    {
        $v = ['name' => $r->displayName()] + $r->vars + $vars;
        $v['link'] = SystemNotification::absoluteUrl($url ?? ($r->audience === Recipient::CORPORATE ? '/corporate' : '/portal'));

        return [
            'key' => $t->key,
            'category' => $t->category,
            'audience' => $r->audience,
            'title' => Str::limit(NotificationTemplate::render($t->title, $v), 180),
            'body' => Str::limit(preg_replace('/\s+/', ' ', NotificationTemplate::render($t->body, $v)) ?? '', 480),
            'url' => $url,
            'mail_subject' => $t->mail_subject ? NotificationTemplate::render($t->mail_subject, $v) : null,
            'mail_body' => $t->mail_body ? NotificationTemplate::render($t->mail_body, $v) : null,
            'wa_body' => $t->wa_body ? Str::limit(NotificationTemplate::render($t->wa_body, $v), 1500) : null,
        ];
    }

    // ------------------------------------------------------------------ penerima (akun peserta)

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
