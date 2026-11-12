<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\PushSubscription;
use App\Models\Trainer;
use App\Services\NotificationCenter;
use App\Services\NotificationDispatcher;
use App\Support\Push\PushSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Notifikasi aplikasi, preferensi & langganan push portal member (PRD §5.12). */
class NotificationController extends Controller
{
    /** GET /me/notifications?filter=unread&page= */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['filter' => ['nullable', 'in:all,unread'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $user = $request->user();
        $q = $user->portalNotifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        if ($request->input('filter') === 'unread') {
            $q->whereNull('read_at');
        }
        $page = $q->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => self::toArray($n))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'unread' => $user->portalNotifications()->whereNull('read_at')->count()],
        ]);
    }

    /** POST /me/notifications/{id}/read */
    public function read(Request $request, string $id, NotificationDispatcher $dispatcher): JsonResponse
    {
        $n = $request->user()->portalNotifications()->whereKey($id)->firstOrFail();
        $n->markAsRead();
        $dispatcher->markRead([$n->id]);

        return response()->json(['data' => self::toArray($n), 'unread' => $request->user()->portalNotifications()->whereNull('read_at')->count()]);
    }

    /** POST /me/notifications/read-all */
    public function readAll(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $ids = $request->user()->portalNotifications()->whereNull('read_at')->pluck('id')->all();
        $count = $request->user()->portalNotifications()->whereNull('read_at')->update(['read_at' => now()]);
        $dispatcher->markRead($ids);

        return response()->json(['data' => ['marked' => $count], 'unread' => 0]);
    }

    /** GET /me/notification-settings — preferensi per kategori + status push. */
    public function settings(Request $request, NotificationCenter $center, PushSender $push, WhatsAppSender $whatsapp): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload($request, $center, $push, $whatsapp)]);
    }

    /** PUT /me/notification-settings {preferences: {category: {mail: bool, push: bool}}} */
    public function updateSettings(Request $request, NotificationCenter $center, PushSender $push, WhatsAppSender $whatsapp): JsonResponse
    {
        $cats = array_keys(NotificationTemplate::CATEGORIES);
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.mail' => ['boolean'],
            'preferences.*.push' => ['boolean'],
            'preferences.*.whatsapp' => ['boolean'],
        ]);
        abort_if(array_diff(array_keys($data['preferences']), $cats) !== [], 422, 'Kategori tidak dikenal.');
        $center->savePreferences($request->user(), $data['preferences']);

        return response()->json(['data' => $this->settingsPayload($request, $center, $push, $whatsapp), 'message' => 'Pengaturan notifikasi disimpan.']);
    }

    /** POST /me/push-subscriptions — simpan langganan dari PushManager.subscribe() (format toJSON()). */
    public function subscribe(Request $request, PushSender $push): JsonResponse
    {
        abort_unless($push->configured(), 503, 'Notifikasi push belum diaktifkan di server.');
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);
        $sub = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        );

        return response()->json(['data' => ['id' => $sub->id, 'devices' => $request->user()->pushSubscriptions()->count()], 'message' => 'Notifikasi push aktif di perangkat ini.'], 201);
    }

    /** DELETE /me/push-subscriptions {endpoint} */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $deleted = $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->delete();

        return response()->json(['data' => ['deleted' => $deleted, 'devices' => $request->user()->pushSubscriptions()->count()]]);
    }

    /** POST /me/push-subscriptions/test — kirim push uji ke semua perangkat akun. */
    public function test(Request $request, NotificationCenter $center): JsonResponse
    {
        abort_unless($request->user()->pushSubscriptions()->exists(), 422, 'Aktifkan notifikasi push di perangkat ini terlebih dahulu.');
        $ok = $center->send($request->user(), 'push.test', [], '/portal/notifikasi', null, ['webpush']);

        return response()->json(['data' => ['sent' => $ok > 0]], $ok ? 200 : 502);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(Request $request, NotificationCenter $center, PushSender $push, WhatsAppSender $whatsapp): array
    {
        $user = $request->user();
        $prefs = $center->preferences($user);
        // kategori khusus peran hanya tampil bagi yang relevan
        $relevant = [
            'corporate' => $user->corporateMemberships()->where('status', 'active')->exists(),
            'teaching' => Trainer::query()->where('user_id', $user->id)->exists(),
            'operations' => $user->isAdmin(),
        ];

        return [
            'categories' => collect(NotificationTemplate::CATEGORIES)->filter(fn ($c, $key) => $relevant[$key] ?? true)->map(fn ($c, $key) => [
                'key' => $key, 'label' => $c[0], 'description' => $c[1],
                'mail' => $prefs[$key]['mail'], 'push' => $prefs[$key]['push'], 'whatsapp' => $prefs[$key]['whatsapp'],
            ])->values(),
            'push' => ['available' => $push->configured(), 'publicKey' => $push->publicKey(), 'devices' => $user->pushSubscriptions()->count()],
            'emailEnabled' => ($user->member?->communication_pref['email'] ?? true) !== false,
            'whatsapp' => [
                'available' => $whatsapp->enabled(),
                'enabled' => ($user->member?->communication_pref['whatsapp'] ?? true) !== false,
                'hasNumber' => LogWhatsAppSender::normalize($user->whatsappNumber()) !== null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function toArray(DatabaseNotification $n): array
    {
        $d = $n->data;

        return [
            'id' => $n->id,
            'key' => $d['key'] ?? $n->type,
            'category' => $d['category'] ?? 'announcement',
            'title' => $d['title'] ?? '',
            'body' => $d['body'] ?? '',
            'url' => $d['url'] ?? null,
            'read' => $n->read_at !== null,
            'createdAt' => $n->created_at?->toIso8601String(),
        ];
    }
}
