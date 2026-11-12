<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\ApiClient;
use App\Models\Certificate;
use App\Models\Invoice;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;

/**
 * Spesifikasi OpenAPI 3.1 untuk API Publik v1 (sumber tunggal dokumentasi /docs/api & /api/public/v1/openapi.json).
 * Tes PublicIntegrationApiTest memastikan setiap route API Publik terdokumentasi di sini.
 */
final class PublicApiSpec
{
    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        $scopeList = collect(ApiClient::allScopes())->map(fn ($d, $s) => "- `$s`: $d")->implode("\n");
        $eventList = collect(ApiClient::allWebhookEvents())->map(fn ($d, $e) => "- `$e`: $d")->implode("\n");

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API Publik PPTI VMES',
                'version' => '1.0.0',
                'summary' => 'Integrasi katalog, pendaftaran & verifikasi sertifikat Pusat Pelatihan Teknologi Industri.',
                'description' => implode("\n\n", [
                    'API REST untuk mitra, klien korporasi, principal vendor dan agregator pelatihan. Semua respons JSON (UTF-8), tanggal ISO 8601, nominal dalam Rupiah (IDR).',
                    '**Autentikasi** — kirim API key di header `X-Api-Key` (atau `Authorization: Bearer <key>`). Key diberikan admin PPTI dan hanya ditampilkan sekali; simpan di sisi server, jangan di aplikasi klien/browser.',
                    "**Scope** — setiap key hanya dapat memanggil endpoint sesuai scope-nya:\n$scopeList",
                    '**Rate limit** — default 60 permintaan/menit per klien (dapat disesuaikan). Header `X-RateLimit-Limit` & `X-RateLimit-Remaining` dikirim di tiap respons; bila terlampaui → `429` + `Retry-After`.',
                    '**Galat** — selalu berbentuk `{"error": {"code": "...", "message": "...", "fields": {...}}}` (`fields` hanya untuk `validation_failed`).',
                    '**Paginasi** — endpoint daftar memakai `page` & `per_page` (maks. 100) dan mengembalikan `data`, `links`, `meta`.',
                    '**Idempotensi** — `POST /registrations` dengan `external_ref` yang sama tidak membuat pendaftaran ganda; respons `200` + header `Idempotent-Replayed: true`. Header `Idempotency-Key` juga diterima di semua `POST` (wajib untuk endpoint enterprise, lihat di bawah).',
                    "**Webhook** — bila URL webhook didaftarkan, PPTI mengirim `POST` JSON untuk event:\n$eventList\n\nHeader: `X-PPTI-Event`, `X-PPTI-Delivery` (UUID unik), `X-PPTI-Signature: t=<unix>,v1=<hex>` dengan `v1 = HMAC-SHA256(secret, \"<t>.<raw body>\")`. Balas `2xx` dalam 10 detik; kegagalan diulang hingga 5 kali (1 mnt, 5 mnt, 15 mnt, 1 jam). Tolak tanda tangan yang tidak cocok atau `t` lebih dari 5 menit. Event `[Enterprise]` hanya dikirim ke klien terikat organisasi yang juga punya scope baca terkait, dan pakai `X-PPTI-Delivery` untuk membuang kiriman ganda.",
                    EnterpriseApiSpec::description(),
                ]),
                'contact' => ['name' => 'PPTI VMES', 'url' => rtrim((string) config('services.frontend.url'), '/').'/kontak'],
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/public/v1', 'description' => 'Server '.config('app.env')]],
            'security' => [['ApiKey' => []]],
            'tags' => [
                ['name' => 'Klien', 'description' => 'Cek koneksi & identitas klien'],
                ['name' => 'Katalog', 'description' => 'Program, kategori & jadwal kelas (scope `catalog.read`)'],
                ['name' => 'Pendaftaran', 'description' => 'Pendaftaran atas nama peserta (scope `registrations.write` / `registrations.read`)'],
                ['name' => 'Sertifikat', 'description' => 'Verifikasi sertifikat (scope `certificates.verify`)'],
                ...EnterpriseApiSpec::TAGS,
            ],
            'paths' => self::paths() + EnterpriseApiSpec::paths(),
            'webhooks' => self::webhooks(),
            'components' => [
                'securitySchemes' => ['ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']],
                'schemas' => self::schemas() + EnterpriseApiSpec::schemas(),
                'parameters' => [
                    'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    'per_page' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'description' => 'Nilai unik per transaksi (mis. UUID v4). Diulang dengan isi sama → respons pertama diputar ulang.', 'schema' => ['type' => 'string', 'maxLength' => 255, 'pattern' => '^[A-Za-z0-9._:\\-]+$']],
                    'updated_since' => ['name' => 'updated_since', 'in' => 'query', 'description' => 'Hanya data yang berubah sejak waktu ini (sinkronisasi inkremental).', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                ],
                'responses' => [
                    'Unauthenticated' => self::errorResponse('API key tidak ada, salah, atau kedaluwarsa (`unauthenticated`, `key_expired`).'),
                    'Forbidden' => self::errorResponse('Klien nonaktif, IP tidak diizinkan, scope/peran kurang, atau klien bukan klien organisasi (`client_disabled`, `ip_not_allowed`, `insufficient_scope`, `insufficient_role`, `organization_required`, `organization_inactive`, `acting_user_inactive`).'),
                    'IdempotencyKeyMissing' => self::errorResponse('Header Idempotency-Key tidak ada atau formatnya salah (`idempotency_key_required`, `idempotency_key_invalid`).'),
                    'IdempotencyInProgress' => self::errorResponse('Permintaan dengan Idempotency-Key yang sama masih diproses (`idempotency_in_progress`); coba lagi sesuai `Retry-After`.'),
                    'NotFound' => self::errorResponse('Data tidak ditemukan (`not_found`).'),
                    'ValidationFailed' => self::errorResponse('Input tidak valid atau ditolak aturan bisnis (`validation_failed`, `schedule_not_found`, `registration_closed`, `insufficient_seats`, `idempotency_key_reused`, `identity_unresolved`).'),
                    'RateLimited' => self::errorResponse('Batas permintaan per menit terlampaui (`rate_limited`).'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paths(): array
    {
        $common = ['401' => ['$ref' => '#/components/responses/Unauthenticated'], '403' => ['$ref' => '#/components/responses/Forbidden'], '429' => ['$ref' => '#/components/responses/RateLimited']];
        $page = [['$ref' => '#/components/parameters/page'], ['$ref' => '#/components/parameters/per_page'], ['$ref' => '#/components/parameters/updated_since']];
        $q = fn (string $name, string $desc, array $schema = ['type' => 'string']) => ['name' => $name, 'in' => 'query', 'description' => $desc, 'schema' => $schema];
        $path = fn (string $name, string $desc) => ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $desc, 'schema' => ['type' => 'string']];
        $modes = ['type' => 'string', 'enum' => array_keys(TrainingPackage::MODE_LABELS)];

        return [
            '/' => ['get' => [
                'tags' => ['Klien'], 'operationId' => 'getClient', 'summary' => 'Identitas klien, scope & batas permintaan',
                'responses' => ['200' => self::ok(['$ref' => '#/components/schemas/Client'])] + $common,
            ]],
            '/categories' => ['get' => [
                'tags' => ['Katalog'], 'operationId' => 'listCategories', 'summary' => 'Daftar kategori program aktif',
                'responses' => ['200' => self::ok(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Category']])] + $common,
            ]],
            '/programs' => ['get' => [
                'tags' => ['Katalog'], 'operationId' => 'listPrograms', 'summary' => 'Daftar program terpublikasi',
                'parameters' => array_merge([
                    $q('q', 'Cari judul, vendor, ringkasan atau kategori'),
                    $q('category', 'Slug kategori (termasuk subkategori)'),
                    $q('mode', 'Metode pelaksanaan', $modes),
                    $q('program_type', 'Jenis program', ['type' => 'string', 'enum' => array_keys(TrainingPackage::PROGRAM_TYPES)]),
                ], $page),
                'responses' => ['200' => self::paginated('#/components/schemas/Program')] + $common + ['422' => ['$ref' => '#/components/responses/ValidationFailed']],
            ]],
            '/programs/{slug}' => ['get' => [
                'tags' => ['Katalog'], 'operationId' => 'getProgram', 'summary' => 'Detail program + silabus + jadwal mendatang',
                'parameters' => [$path('slug', 'Slug program')],
                'responses' => ['200' => self::ok(['$ref' => '#/components/schemas/ProgramDetail']), '404' => ['$ref' => '#/components/responses/NotFound']] + $common,
            ]],
            '/schedules' => ['get' => [
                'tags' => ['Katalog'], 'operationId' => 'listSchedules', 'summary' => 'Daftar jadwal kelas terpublikasi (default: belum berakhir)',
                'parameters' => array_merge([
                    $q('program', 'Slug atau kode program'),
                    $q('category', 'Slug kategori (termasuk subkategori)'),
                    $q('from', 'Tanggal mulai ≥', ['type' => 'string', 'format' => 'date']),
                    $q('to', 'Tanggal mulai ≤', ['type' => 'string', 'format' => 'date']),
                    $q('city', 'Kota'),
                    $q('mode', 'Metode pelaksanaan', $modes),
                    $q('open', 'Hanya yang menerima pendaftaran', ['type' => 'boolean']),
                    $q('include_past', 'Sertakan kelas yang sudah berakhir', ['type' => 'boolean']),
                ], $page),
                'responses' => ['200' => self::paginated('#/components/schemas/Schedule')] + $common + ['422' => ['$ref' => '#/components/responses/ValidationFailed']],
            ]],
            '/schedules/{code}' => ['get' => [
                'tags' => ['Katalog'], 'operationId' => 'getSchedule', 'summary' => 'Detail jadwal kelas',
                'parameters' => [$path('code', 'Kode kelas, mis. CCNA-2611')],
                'responses' => ['200' => self::ok(['$ref' => '#/components/schemas/Schedule']), '404' => ['$ref' => '#/components/responses/NotFound']] + $common,
            ]],
            '/registrations' => [
                'post' => [
                    'tags' => ['Pendaftaran'], 'operationId' => 'createRegistration', 'summary' => 'Buat pendaftaran individu atau kolektif perusahaan',
                    'description' => 'Pendaftaran masuk dengan status `pending` dan diproses admin PPTI seperti pendaftaran website (verifikasi, tagihan, konfirmasi). Pantau perubahan lewat webhook `registration.status_changed` atau `GET /registrations/{number}`.',
                    'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Opsional (selain `external_ref`).', 'schema' => ['type' => 'string', 'maxLength' => 255]]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/RegistrationInput']]]],
                    'responses' => [
                        '201' => self::ok(['$ref' => '#/components/schemas/Registration'], 'Pendaftaran dibuat'),
                        '200' => self::ok(['$ref' => '#/components/schemas/Registration'], 'Sudah ada pendaftaran dengan `external_ref` yang sama (idempoten)'),
                        '422' => ['$ref' => '#/components/responses/ValidationFailed'],
                    ] + $common,
                ],
                'get' => [
                    'tags' => ['Pendaftaran'], 'operationId' => 'listRegistrations', 'summary' => 'Daftar pendaftaran yang dibuat klien ini',
                    'parameters' => array_merge([
                        $q('status', 'Status pendaftaran', ['type' => 'string', 'enum' => array_column(RegistrationStatus::cases(), 'value')]),
                        $q('external_ref', 'Referensi sistem klien'),
                    ], $page),
                    'responses' => ['200' => self::paginated('#/components/schemas/Registration')] + $common,
                ],
            ],
            '/registrations/{number}' => ['get' => [
                'tags' => ['Pendaftaran'], 'operationId' => 'getRegistration', 'summary' => 'Status & detail satu pendaftaran milik klien ini',
                'parameters' => [$path('number', 'Nomor registrasi, mis. REG-202611-0001')],
                'responses' => ['200' => self::ok(['$ref' => '#/components/schemas/Registration']), '404' => ['$ref' => '#/components/responses/NotFound']] + $common,
            ]],
            '/certificates/{number}' => ['get' => [
                'tags' => ['Sertifikat'], 'operationId' => 'verifyCertificate', 'summary' => 'Verifikasi keaslian & status sertifikat',
                'parameters' => [$path('number', 'Nomor sertifikat, mis. PPTI-KMP-202611-0001'), $q('code', 'Kode verifikasi yang tercetak di sertifikat (opsional)')],
                'responses' => ['200' => self::ok(['$ref' => '#/components/schemas/CertificateVerification']), '404' => ['$ref' => '#/components/responses/NotFound']] + $common,
            ]],
            '/openapi.json' => ['get' => [
                'tags' => ['Klien'], 'operationId' => 'getOpenApi', 'summary' => 'Spesifikasi OpenAPI ini (tanpa autentikasi)', 'security' => [],
                'responses' => ['200' => ['description' => 'Dokumen OpenAPI 3.1', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function webhooks(): array
    {
        $hooks = [];
        foreach (ApiClient::allWebhookEvents() as $event => $desc) {
            $data = str_starts_with($event, 'registration.') ? '#/components/schemas/RegistrationEvent' : EnterpriseApiSpec::webhookSchema($event);
            $hooks[$event] = ['post' => [
                'summary' => $desc,
                'parameters' => [
                    ['name' => 'X-PPTI-Event', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'const' => $event]],
                    ['name' => 'X-PPTI-Delivery', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'X-PPTI-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'example' => 't=1762851600,v1=5f2b...']],
                ],
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['id', 'event', 'created_at', 'data'],
                    'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'event' => ['type' => 'string', 'const' => $event], 'created_at' => ['type' => 'string', 'format' => 'date-time'], 'data' => ['$ref' => $data]],
                ]]]],
                'responses' => ['2XX' => ['description' => 'Diterima']],
            ]];
        }

        return $hooks;
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = fn (bool $nullable = false) => ['type' => $nullable ? ['string', 'null'] : 'string'];
        $i = fn (bool $nullable = false) => ['type' => $nullable ? ['integer', 'null'] : 'integer'];
        $n = fn (bool $nullable = false) => ['type' => $nullable ? ['number', 'null'] : 'number'];
        $date = fn (bool $nullable = false) => ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date'];
        $dt = fn (bool $nullable = false) => ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date-time'];
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $statuses = array_column(RegistrationStatus::cases(), 'value');

        $program = [
            'code' => $s(true), 'slug' => $s(), 'title' => $s(), 'vendor' => $s(true),
            'category' => ['type' => ['object', 'null'], 'properties' => ['slug' => $s(), 'name' => $s()]],
            'excerpt' => $s(true), 'program_type' => ['type' => 'string', 'enum' => array_keys(TrainingPackage::PROGRAM_TYPES)],
            'mode' => ['type' => 'string', 'enum' => array_keys(TrainingPackage::MODE_LABELS)], 'level' => $s(true),
            'duration_days' => $i(), 'duration_hours' => $i(true), 'price' => $n(true), 'currency' => ['type' => 'string', 'const' => 'IDR'],
            'certificate_type' => ['type' => 'string', 'enum' => array_keys(TrainingPackage::CERTIFICATE_TYPES)], 'certificate' => $s(true),
            'availability' => ['type' => 'string', 'enum' => array_keys(TrainingPackage::AVAILABILITY)], 'is_popular' => ['type' => 'boolean'],
            'image_url' => $s(true), 'url' => ['type' => 'string', 'format' => 'uri'], 'updated_at' => $dt(true),
        ];

        return [
            'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => [
                'code' => $s(), 'message' => $s(), 'fields' => ['type' => 'object', 'additionalProperties' => $strings],
            ]]]],
            'Client' => ['type' => 'object', 'properties' => [
                'api_version' => $s(), 'client' => $s(), 'organization' => $s(true),
                'enterprise' => ['type' => ['object', 'null'], 'description' => 'Hanya klien terikat organisasi', 'properties' => ['organization_code' => $s(), 'organization_name' => $s(), 'acting_role' => $s(true), 'acting_user_active' => ['type' => 'boolean']]],
                'scopes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(ApiClient::allScopes())]],
                'rate_limit_per_minute' => $i(), 'webhook_events' => $strings, 'expires_at' => $dt(true), 'server_time' => $dt(),
            ]],
            'Category' => ['type' => 'object', 'properties' => ['slug' => $s(), 'name' => $s(), 'description' => $s(true), 'parent' => $s(true), 'programs_count' => $i()]],
            'Program' => ['type' => 'object', 'properties' => $program],
            'ProgramDetail' => ['allOf' => [['$ref' => '#/components/schemas/Program'], ['type' => 'object', 'properties' => [
                'objectives' => $s(true), 'competencies' => $strings, 'learning_outcomes' => $strings, 'audience' => $strings, 'requirements' => $strings,
                'curriculum' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['title' => $s(), 'topics' => $strings, 'duration_hours' => $i(true), 'method' => $s(true)]]],
                'upcoming_schedules' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Schedule']],
            ]]]],
            'Schedule' => ['type' => 'object', 'properties' => [
                'code' => $s(), 'title' => $s(),
                'program' => ['type' => ['object', 'null'], 'properties' => ['slug' => $s(), 'code' => $s(true), 'title' => $s()]],
                'start_date' => $date(), 'end_date' => $date(), 'start_time' => $s(true), 'end_time' => $s(true), 'timezone' => $s(),
                'city' => $s(true), 'location' => $s(true), 'mode' => $s(),
                'status' => ['type' => 'string', 'enum' => TrainingSchedule::PUBLIC_STATUSES],
                'registration_open' => ['type' => 'boolean'], 'registration_deadline' => $date(true),
                'quota' => $i(true), 'seats_remaining' => $i(true), 'price' => $n(true), 'currency' => ['type' => 'string', 'const' => 'IDR'],
                'trainer' => $s(true), 'url' => ['type' => 'string', 'format' => 'uri'], 'updated_at' => $dt(true),
            ]],
            'RegistrationInput' => ['type' => 'object', 'required' => ['schedule', 'type', 'name', 'email', 'phone', 'consent'], 'properties' => [
                'schedule' => ['type' => 'string', 'description' => 'Kode kelas'],
                'type' => ['type' => 'string', 'enum' => ['individual', 'corporate']],
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Nama pendaftar / PIC'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string', 'maxLength' => 30, 'description' => 'Nomor WhatsApp'],
                'company' => ['type' => 'string', 'description' => 'Wajib untuk `corporate`'],
                'position' => ['type' => 'string'],
                'participants_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Wajib untuk `corporate`'],
                'participants' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => $s(), 'email' => ['type' => 'string', 'format' => 'email'], 'phone' => $s(), 'position' => $s()]]],
                'notes' => ['type' => 'string', 'maxLength' => 2000],
                'consent' => ['type' => 'boolean', 'const' => true, 'description' => 'Persetujuan peserta atas pemrosesan data pribadi'],
                'external_ref' => ['type' => 'string', 'maxLength' => 100, 'description' => 'ID pendaftaran di sistem Anda; kunci idempotensi'],
            ]],
            'Registration' => ['type' => 'object', 'properties' => [
                'number' => $s(), 'external_ref' => $s(true), 'status' => ['type' => 'string', 'enum' => $statuses], 'status_label' => $s(), 'status_note' => $s(true),
                'type' => ['type' => 'string', 'enum' => ['individual', 'corporate']],
                'schedule' => ['type' => ['object', 'null'], 'properties' => ['code' => $s(), 'title' => $s(), 'start_date' => $date(), 'end_date' => $date()]],
                'contact' => ['type' => 'object', 'properties' => ['name' => $s(), 'email' => $s(), 'phone' => $s(), 'company' => $s(true), 'position' => $s(true)]],
                'participants_count' => $i(),
                'participants' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => $s(), 'email' => $s(true), 'phone' => $s(true), 'position' => $s(true)]]],
                'price' => $n(true), 'discount' => $n(), 'total' => $n(true), 'currency' => ['type' => 'string', 'const' => 'IDR'],
                'invoice' => ['type' => ['object', 'null'], 'description' => 'Invoice pertama yang tidak dibatalkan. Pembayaran manual: status berubah hanya setelah verifikasi finance VMES.', 'properties' => ['number' => $s(), 'amount' => array_merge($n(), ['description' => 'Total invoice termasuk pajak']), 'amount_paid' => $n(), 'status' => ['type' => 'string', 'enum' => array_keys(Invoice::STATUSES)], 'due_date' => $date(true)]],
                'created_at' => $dt(), 'updated_at' => $dt(), 'confirmed_at' => $dt(true),
            ]],
            'CertificateVerification' => ['type' => 'object', 'properties' => [
                'number' => $s(), 'status' => ['type' => 'string', 'enum' => ['pending', 'issued', 'revoked', 'replaced', 'expired']], 'status_label' => $s(),
                'valid' => ['type' => 'boolean'], 'code_matches' => ['type' => ['boolean', 'null'], 'description' => '`null` bila `code` tidak dikirim'],
                'type' => ['type' => 'string', 'enum' => array_keys(Certificate::TYPES)], 'type_label' => $s(),
                'recipient_name' => $s(true), 'program_title' => $s(true), 'class_code' => $s(true), 'city' => $s(true), 'hours' => $i(true), 'predicate' => $s(true),
                'issuer' => $s(true), 'signatory' => $s(true), 'issued_at' => $date(true), 'valid_until' => $date(true), 'revoked_at' => $date(true), 'replaced_by' => $s(true),
                'competencies' => ['type' => 'array', 'items' => []],
            ]],
            'RegistrationEvent' => ['type' => 'object', 'properties' => [
                'number' => $s(), 'external_ref' => $s(true), 'status' => ['type' => 'string', 'enum' => $statuses], 'previous_status' => ['type' => ['string', 'null'], 'description' => 'Hanya untuk `registration.status_changed`'],
                'status_label' => $s(), 'status_note' => $s(true), 'schedule_code' => $s(true), 'program' => $s(true), 'participants_count' => $i(), 'total' => $n(true), 'updated_at' => $dt(),
            ]],
            'CertificateEvent' => ['type' => 'object', 'properties' => [
                'number' => $s(), 'status' => ['type' => 'string', 'enum' => ['issued', 'revoked']], 'type' => $s(), 'recipient_name' => $s(), 'program' => $s(true), 'class_code' => $s(true),
                'issued_at' => $date(true), 'valid_until' => $date(true), 'revoked_at' => $date(true), 'verification_url' => ['type' => 'string', 'format' => 'uri'],
                'registration_number' => $s(true), 'external_ref' => $s(true),
                'enrollment_id' => array_merge($i(), ['description' => 'Hanya event untuk klien enterprise (enrollment bersponsor)']), 'member_number' => $s(true),
            ]],
            'PaginationLinks' => ['type' => 'object', 'properties' => ['first' => $s(true), 'last' => $s(true), 'prev' => $s(true), 'next' => $s(true)]],
            'PaginationMeta' => ['type' => 'object', 'properties' => ['current_page' => $i(), 'from' => $i(true), 'last_page' => $i(), 'per_page' => $i(), 'to' => $i(true), 'total' => $i(), 'path' => $s()]],
        ];
    }

    /** @return array<string, mixed> */
    private static function ok(array $schema, string $description = 'Berhasil'): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $schema]]]]];
    }

    /** @return array<string, mixed> */
    private static function paginated(string $ref): array
    {
        return ['description' => 'Berhasil (berhalaman)', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => ['$ref' => $ref]],
            'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
            'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
        ]]]]];
    }

    /** @return array<string, mixed> */
    private static function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }
}
