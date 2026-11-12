<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Klien API Publik (integrasi pihak ketiga: mitra, korporasi, principal, agregator).
 * Autentikasi memakai API key `ppti_<prefix>_<rahasia>` yang hanya disimpan sebagai hash sha256.
 *
 * Roadmap 5E — klien ENTERPRISE terikat satu organisasi dan bertindak atas nama satu pengguna perusahaan aktif
 * organisasi itu: data selalu dibatasi organisasi tsb (tidak ada parameter organisasi di URL/API, §20.21), dan
 * peran + batas unit pengguna perusahaan itu ikut membatasi scope. Menonaktifkan pengguna perusahaan (mis. karena
 * resign) otomatis memutus akses klien.
 */
class ApiClient extends Model
{
    public const KEY_PREFIX = 'ppti_';

    /** Scope mitra/umum (tidak butuh organisasi). */
    public const SCOPES = [
        'catalog.read' => 'Membaca katalog program, kategori & jadwal kelas',
        'registrations.write' => 'Membuat pendaftaran atas nama peserta',
        'registrations.read' => 'Membaca status pendaftaran yang dibuat klien ini',
        'certificates.verify' => 'Memverifikasi keaslian sertifikat',
    ];

    /** Scope enterprise (PRD Roadmap 5 §7) — hanya untuk klien yang terikat organisasi. */
    public const ENTERPRISE_SCOPES = [
        'organization.read' => 'Profil organisasi, unit & peran klien',
        'corporate.requests.read' => 'Membaca Corporate Training Request & kursinya',
        'corporate.requests.write' => 'Membuat, mengirim, menerima penawaran, menyetujui & membatalkan request',
        'corporate.employees.read' => 'Membaca direktori karyawan (employment relationship)',
        'corporate.employees.write' => 'Sinkron HRIS: mencatat karyawan (member terverifikasi) & mengakhiri hubungan kerja',
        'enrollments.read' => 'Membaca enrollment yang disponsori organisasi',
        'enrollments.write' => 'Menugaskan peserta ke kursi & membatalkan satu peserta',
        'progress.read' => 'Progres LMS peserta bersponsor',
        'attendance.read' => 'Kehadiran peserta bersponsor',
        'assessments.read' => 'Nilai kuis/tugas & penilaian kompetensi tervalidasi peserta bersponsor',
        'certificates.read' => 'Status sertifikat dari enrollment bersponsor',
        'payments.read' => 'Invoice organisasi & status transaksi pembayaran',
        'notifications.read' => 'Status pengiriman notifikasi ke pengguna perusahaan',
        'analytics.read' => 'KPI ringkas organisasi (sesuai scope perusahaan)',
    ];

    /**
     * Kemampuan peran perusahaan (CorporateUser::CAPABILITIES) yang dibutuhkan tiap scope enterprise — salah satu cukup.
     * Daftar kosong = semua peran.
     */
    public const SCOPE_CAPABILITIES = [
        'organization.read' => [],
        'corporate.requests.read' => [],
        'corporate.requests.write' => ['manage_requests', 'approve_requests'],
        'corporate.employees.read' => ['manage_participants', 'view_progress'],
        'corporate.employees.write' => ['manage_participants'],
        'enrollments.read' => ['manage_participants', 'view_progress'],
        'enrollments.write' => ['manage_participants'],
        'progress.read' => ['view_progress'],
        'attendance.read' => ['view_progress'],
        'assessments.read' => ['view_progress'],
        'certificates.read' => [],
        'payments.read' => ['view_finance'],
        'notifications.read' => [],
        'analytics.read' => [],
    ];

    /** Event webhook keluar untuk pendaftaran yang dibuat klien ini (dan sertifikat enrollment bersponsor untuk klien enterprise). */
    public const WEBHOOK_EVENTS = [
        'registration.created' => 'Pendaftaran dibuat lewat API',
        'registration.status_changed' => 'Status pendaftaran berubah',
        'certificate.issued' => 'Sertifikat terbit (peserta dari pendaftaran klien, atau enrollment bersponsor organisasi klien)',
        'certificate.revoked' => 'Sertifikat dicabut (peserta dari pendaftaran klien, atau enrollment bersponsor organisasi klien)',
    ];

    /** Event webhook enterprise — hanya untuk klien terikat organisasi, berisi data organisasi itu saja. */
    public const ENTERPRISE_WEBHOOK_EVENTS = [
        'training_request.status_changed' => 'Status Corporate Training Request berubah',
        'enrollment.created' => 'Enrollment bersponsor organisasi dibuat',
        'enrollment.status_changed' => 'Status enrollment bersponsor berubah (hadir, lulus, tidak lulus, batal)',
        'enrollment.sponsorship_transferred' => 'Sponsor enrollment dialihkan masuk/keluar organisasi (transfer resmi)',
        'employee.status_changed' => 'Hubungan kerja karyawan dimulai atau berakhir',
        'invoice.status_changed' => 'Status invoice organisasi berubah (lunas, ditolak, kedaluwarsa, refund, batal)',
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

    /** Scope/event enterprise tanpa organisasi, atau pengguna perusahaan dari organisasi lain, ditolak di level model. */
    protected static function booted(): void
    {
        static::saving(function (ApiClient $c): void {
            if ($c->corporate_user_id && ! $c->organization_id) {
                throw new DomainException('Pengguna perusahaan hanya bisa dipilih untuk klien yang terikat organisasi.');
            }
            if ($c->corporate_user_id && (int) CorporateUser::query()->whereKey($c->corporate_user_id)->value('organization_id') !== (int) $c->organization_id) {
                throw new DomainException('Pengguna perusahaan harus berasal dari organisasi klien ini.');
            }
            if ($c->usesEnterpriseFeatures() && (! $c->organization_id || ! $c->corporate_user_id)) {
                throw new DomainException('Scope/event enterprise membutuhkan organisasi dan pengguna perusahaan yang diwakili.');
            }
        });
    }

    /** @return array<string, string> */
    public static function allScopes(): array
    {
        return self::SCOPES + self::ENTERPRISE_SCOPES;
    }

    /** @return array<string, string> */
    public static function allWebhookEvents(): array
    {
        return self::WEBHOOK_EVENTS + self::ENTERPRISE_WEBHOOK_EVENTS;
    }

    public static function isEnterpriseScope(string $scope): bool
    {
        return array_key_exists($scope, self::ENTERPRISE_SCOPES);
    }

    public function usesEnterpriseFeatures(): bool
    {
        return array_intersect($this->scopes ?? [], array_keys(self::ENTERPRISE_SCOPES)) !== []
            || array_intersect($this->webhook_events ?? [], array_keys(self::ENTERPRISE_WEBHOOK_EVENTS)) !== [];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Organisasi tempat klien terikat (kolom teks lama `organization` hanya label bebas). */
    public function boundOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function corporateUser(): BelongsTo
    {
        return $this->belongsTo(CorporateUser::class);
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

    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(ApiIdempotencyKey::class);
    }

    public function isEnterprise(): bool
    {
        return $this->organization_id !== null;
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
