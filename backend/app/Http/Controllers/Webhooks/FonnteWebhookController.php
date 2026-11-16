<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\NotificationCenter;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhook Fonnte (diatur di dashboard Fonnte → Device → Edit; Auto Read = ON).
 * Fonnte tidak menandatangani webhook → diamankan dengan secret acak di path (FONNTE_WEBHOOK_SECRET, dibandingkan hash_equals);
 * secret kosong/salah = 404. Payload valid selalu dibalas cepat 200 {"ok":true}.
 * Semua kode khusus Fonnte ada di sini dan di FonnteWhatsAppSender; Notification Engine hanya melihat receipt().
 */
class FonnteWebhookController extends Controller
{
    /**
     * Pemetaan nilai `state` webhook status pesan → status pengiriman engine.
     * TODO: arti nilai status/state Fonnte TIDAK didokumentasikan. Konfirmasi dari payload asli di log
     * `[fonnte:webhook-status]` setelah webhook aktif, lalu sesuaikan dua konstanta ini. Nilai lain diabaikan.
     */
    public const STATE_MAP = [
        'delivered' => 'delivered',
        'read' => 'read',
    ];

    /** Pemetaan nilai `status` (dipakai bila `state` tidak dikenali). TODO: konfirmasi dari payload asli. */
    public const STATUS_MAP = [
        'failed' => 'failed',
        'invalid' => 'failed',
    ];

    /** Maks. satu peringatan "device terputus" per device per jangka ini. */
    public const DISCONNECT_ALERT_SECONDS = 3600;

    public function status(Request $request, string $secret, NotificationDispatcher $dispatcher): JsonResponse
    {
        $this->guard($secret);
        $payload = $request->all();
        Log::info('[fonnte:webhook-status]', ['payload' => $payload]);

        $id = $this->str($payload['id'] ?? null);
        $status = self::map($payload);
        if ($id === null || $status === null) {
            return $this->ok();   // nilai belum dikenali → cukup dicatat di log
        }
        $state = $this->str($payload['state'] ?? null) ?? $this->str($payload['status'] ?? null);
        $dispatcher->receipt('fonnte', $id, $status, null, $status === 'failed' ? 'Fonnte: '.$state : null);

        return $this->ok();
    }

    public function device(Request $request, string $secret, NotificationCenter $center, NotificationRecipients $recipients): JsonResponse
    {
        $this->guard($secret);
        $device = $this->str($request->input('device')) ?? '-';
        $status = strtolower($this->str($request->input('status')) ?? '');
        $reason = $this->str($request->input('reason'));

        if ($status === 'disconnect') {
            Log::warning('[fonnte:webhook-device] device terputus', ['device' => $device, 'reason' => $reason, 'timestamp' => $request->input('timestamp')]);
            // Cache::add atomik: hanya request pertama dalam jangka waktu yang mengirim peringatan
            if (Cache::add('fonnte:device-disconnect-alert:'.$device, true, self::DISCONNECT_ALERT_SECONDS)) {
                try {
                    $center->publish('whatsapp.device_disconnected', $recipients->admins('notifications.manage'), [
                        'device' => $device, 'reason' => $reason ?? 'tidak disebutkan', 'provider' => 'Fonnte',
                    ], ['url' => '/admin/notification-deliveries', 'actor' => null]);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } else {
            Log::info('[fonnte:webhook-device] status '.($status ?: '-'), ['device' => $device]);
        }

        return $this->ok();
    }

    /** Status pengiriman engine dari payload webhook status pesan; null = tidak dikenali (diabaikan). */
    public static function map(array $payload): ?string
    {
        $state = strtolower(trim((string) (is_scalar($payload['state'] ?? null) ? $payload['state'] : '')));
        $status = strtolower(trim((string) (is_scalar($payload['status'] ?? null) ? $payload['status'] : '')));

        return self::STATE_MAP[$state] ?? self::STATUS_MAP[$status] ?? null;
    }

    private function guard(string $secret): void
    {
        $expected = (string) config('notifications.whatsapp.fonnte.webhook_secret');
        abort_if($expected === '' || ! hash_equals($expected, $secret), 404);
    }

    private function str(mixed $v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? Str::limit(trim((string) $v), 190, '') : null;
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
