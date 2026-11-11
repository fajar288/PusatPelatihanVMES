<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Klien API Publik (integrasi pihak ketiga: mitra, korporasi, principal, agregator).
 * Autentikasi memakai API key `ppti_<prefix>_<rahasia>` yang hanya disimpan sebagai hash sha256.
 */
class ApiClient extends Model
{
    public const KEY_PREFIX = 'ppti_';

    /** Scope (izin) per klien. */
    public const SCOPES = [
        'catalog.read' => 'Membaca katalog program, kategori & jadwal kelas',
        'registrations.write' => 'Membuat pendaftaran atas nama peserta',
        'registrations.read' => 'Membaca status pendaftaran yang dibuat klien ini',
        'certificates.verify' => 'Memverifikasi keaslian sertifikat',
    ];

    /** Event webhook keluar. */
    public const WEBHOOK_EVENTS = [
        'registration.created' => 'Pendaftaran dibuat lewat API',
        'registration.status_changed' => 'Status pendaftaran berubah',
        'certificate.issued' => 'Sertifikat peserta dari pendaftaran klien terbit',
        'certificate.revoked' => 'Sertifikat peserta dari pendaftaran klien dicabut',
    ];

    protected $guarded = [];

    /** Selaras dengan default kolom agar model baru (belum di-refresh) berperilaku sama. */
    protected $attributes = ['is_active' => true, 'rate_limit' => 60];

    protected $hidden = ['key_hash', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'webhook_events' => 'array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'key_rotated_at' => 'datetime',
            'rate_limit' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** Buat key baru (mengganti yang lama bila ada). Mengembalikan key asli — tampilkan sekali saja. */
    public function generateKey(): string
    {
        $prefix = strtolower(Str::random(8));
        $key = self::KEY_PREFIX.$prefix.'_'.Str::random(40);
        $this->forceFill([
            'key_prefix' => self::KEY_PREFIX.$prefix,
            'key_hash' => self::hashKey($key),
            'key_rotated_at' => $this->exists ? now() : null,
        ]);

        return $key;
    }

    public function generateWebhookSecret(): string
    {
        $secret = 'whsec_'.Str::random(40);
        $this->forceFill(['webhook_secret' => $secret]);

        return $secret;
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function findByKey(?string $key): ?self
    {
        if (! $key || ! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        return self::query()->where('key_hash', self::hashKey($key))->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function allowsIp(?string $ip): bool
    {
        $list = array_values(array_filter($this->allowed_ips ?? []));

        return $list === [] || ($ip !== null && IpUtils::checkIp($ip, $list));
    }

    public function wantsWebhook(string $event): bool
    {
        return $this->is_active && filled($this->webhook_url) && filled($this->webhook_secret) && in_array($event, $this->webhook_events ?? [], true);
    }

    public function maskedKey(): string
    {
        return $this->key_prefix.'_••••••••';
    }
}
