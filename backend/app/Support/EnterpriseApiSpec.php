<?php

namespace App\Support;

use App\Models\ApiClient;
use App\Models\Certificate;
use App\Models\CorporateUser;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\PaymentTransaction;
use App\Models\RequestSeat;
use App\Models\TrainingRequest;

/**
 * Bagian OpenAPI untuk API enterprise (Roadmap 5E, PRD Roadmap 5 §7) — digabung oleh PublicApiSpec.
 * Tes PublicIntegrationApiTest memastikan setiap route terdokumentasi; EnterpriseApiTest mencocokkan bentuk respons.
 */
final class EnterpriseApiSpec
{
    public const TAGS = [
        ['name' => 'Enterprise: Organisasi', 'description' => 'Profil organisasi terikat & KPI ringkas (scope `organization.read`, `analytics.read`)'],
        ['name' => 'Enterprise: Request', 'description' => 'Corporate Training Request, kursi & penugasan peserta (scope `corporate.requests.*`, `enrollments.write`)'],
        ['name' => 'Enterprise: Karyawan', 'description' => 'Direktori karyawan & sinkron HRIS (scope `corporate.employees.*`)'],
        ['name' => 'Enterprise: Enrollment', 'description' => 'Enrollment bersponsor, progres, kehadiran, penilaian & sertifikat (scope `enrollments.read`, `progress.read`, `attendance.read`, `assessments.read`, `certificates.read`)'],
        ['name' => 'Enterprise: Keuangan & notifikasi', 'description' => 'Status invoice/pembayaran manual & pengiriman notifikasi (scope `payments.read`, `notifications.read`)'],
    ];

    public static function description(): string
    {
        $caps = collect(ApiClient::SCOPE_CAPABILITIES)->map(fn ($c, $s) => "- `$s`: ".($c === [] ? 'semua peran' : 'peran dengan '.implode(' / ', array_map(fn ($x) => "`$x`", $c))))->implode("\n");

        return implode("\n\n", [
            '**API enterprise** — untuk klien yang diikat admin PPTI ke satu organisasi. Klien bertindak atas nama satu pengguna perusahaan aktif; semua data otomatis dibatasi organisasi itu, batas unit pengguna tsb., dan hanya enrollment yang disponsori organisasi (enrollment mandiri/self-funded dan sponsor lain tidak pernah muncul). Tidak ada parameter organisasi — data organisasi lain dijawab `404`. Bila pengguna perusahaan yang diwakili dinonaktifkan (mis. resign), klien ditolak `403 acting_user_inactive`.',
            "Scope enterprise juga mensyaratkan peran pengguna perusahaan yang diwakili (`403 insufficient_role` bila tidak):\n$caps",
            '**Privasi** — respons tidak memuat email/HP peserta, catatan privat trainer, catatan internal VMES, maupun berkas bukti transfer. Progres hanya dari logbook & penilaian yang sudah divalidasi. Peserta yang sudah keluar dari organisasi mengikuti kebijakan akses histori organisasi (`exit_access`).',
            '**Idempotency-Key** — wajib untuk semua `POST` enterprise (opsional untuk `POST /registrations`). Kirim nilai unik per transaksi (mis. UUID v4, maks. 255 karakter `A-Z a-z 0-9 . _ : -`). Mengulang key + isi yang sama dalam 24 jam memutar ulang respons sukses pertama (header `Idempotent-Replayed: true`) tanpa mengulang aksi. Key yang sama dengan isi berbeda → `422 idempotency_key_reused`; permintaan pertama masih diproses → `409 idempotency_in_progress`. Respons galat tidak disimpan, sehingga key yang sama boleh dipakai lagi setelah data diperbaiki.',
            '**Versi** — path `/api/public/v1` dan header respons `API-Version: 1`. Perubahan yang tidak kompatibel akan memakai `/v2`; field baru dapat ditambahkan di v1 kapan saja, jadi abaikan field yang tidak dikenal.',
        ]);
    }

    /** @return array<string, mixed> */
    public static function paths(): array
    {
        $common = ['401' => ['$ref' => '#/components/responses/Unauthenticated'], '403' => ['$ref' => '#/components/responses/Forbidden'], '429' => ['$ref' => '#/components/responses/RateLimited']];
        $page = [['$ref' => '#/components/parameters/page'], ['$ref' => '#/components/parameters/per_page'], ['$ref' => '#/components/parameters/updated_since']];
        $idem = [['$ref' => '#/components/parameters/IdempotencyKey']];
        $write = ['400' => ['$ref' => '#/components/responses/IdempotencyKeyMissing'], '409' => ['$ref' => '#/components/responses/IdempotencyInProgress'], '422' => ['$ref' => '#/components/responses/ValidationFailed']];
        $q = fn (string $name, string $desc, array $schema = ['type' => 'string']) => ['name' => $name, 'in' => 'query', 'description' => $desc, 'schema' => $schema];
        $path = fn (string $name, string $desc, string $type = 'string') => ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $desc, 'schema' => ['type' => $type]];
        $body = fn (string $ref) => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => $ref]]]];
        $ok = fn (array $schema, string $d = 'Berhasil') => ['description' => $d, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $schema]]]]];
        $ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
        $list = fn (string $name) => ['description' => 'Berhasil (berhalaman)', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$name]],
            'links' => ['$ref' => '#/components/schemas/PaginationLinks'], 'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
        ]]]]];
        $nf = ['404' => ['$ref' => '#/components/responses/NotFound']];
        $enrollmentFilters = [
            $q('enrollment', 'ID enrollment', ['type' => 'integer']), $q('schedule', 'Kode kelas'), $q('request', 'Kode Corporate Training Request'),
            $q('employee_id', 'Employee ID'), $q('unit', 'Kode unit sponsor'),
        ];
        $learning = $q('status', 'Status enrollment', ['type' => 'string', 'enum' => ['enrolled', 'attending', 'completed', 'failed']]);
        $T = fn (string $t) => ['Enterprise: '.$t];

        return [
            '/corporate/organization' => ['get' => [
                'tags' => $T('Organisasi'), 'operationId' => 'getOrganization', 'summary' => 'Organisasi terikat, peran yang diwakili & unit',
                'responses' => ['200' => $ok($ref('Organization'))] + $common,
            ]],
            '/analytics' => ['get' => [
                'tags' => $T('Organisasi'), 'operationId' => 'getAnalytics', 'summary' => 'KPI ringkas organisasi (enrollment bersponsor saja)',
                'responses' => ['200' => $ok($ref('Analytics'))] + $common,
            ]],
            '/corporate/requests' => [
                'get' => [
                    'tags' => $T('Request'), 'operationId' => 'listTrainingRequests', 'summary' => 'Daftar Corporate Training Request',
                    'parameters' => array_merge([$q('status', 'Status request', ['type' => 'string', 'enum' => array_keys(TrainingRequest::STATUSES)])], $page),
                    'responses' => ['200' => $list('TrainingRequest')] + $common,
                ],
                'post' => [
                    'tags' => $T('Request'), 'operationId' => 'createTrainingRequest', 'summary' => 'Buat request dengan jumlah kursi (tanpa nama peserta)',
                    'description' => 'Kursi 1..N dibuat sebagai `reserved` tanpa membuat member. `submit=true` langsung mengirim ke VMES untuk penawaran. Peran: `manage_requests`.',
                    'parameters' => $idem, 'requestBody' => $body('#/components/schemas/TrainingRequestInput'),
                    'responses' => ['201' => $ok($ref('TrainingRequestDetail'), 'Request dibuat')] + $write + $common,
                ],
            ],
            '/corporate/requests/{code}' => ['get' => [
                'tags' => $T('Request'), 'operationId' => 'getTrainingRequest', 'summary' => 'Detail request + kursi + invoice',
                'parameters' => [$path('code', 'Kode request, mis. REQ-202611-0001')],
                'responses' => ['200' => $ok($ref('TrainingRequestDetail'))] + $nf + $common,
            ]],
            '/corporate/requests/{code}/{action}' => ['post' => [
                'tags' => $T('Request'), 'operationId' => 'transitionTrainingRequest', 'summary' => 'Kirim, terima penawaran, setujui, atau batalkan request',
                'description' => '`submit` (draft → submitted), `accept-quote` (quoted → approval/approved sesuai kebijakan persetujuan), `approve` (approval → approved, peran `approve_requests`), `cancel` (sebelum disetujui).',
                'parameters' => array_merge([$path('code', 'Kode request'), ['name' => 'action', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['submit', 'accept-quote', 'approve', 'cancel']]]], $idem),
                'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'maxLength' => 1000]]]]]],
                'responses' => ['200' => $ok($ref('TrainingRequestDetail'))] + $nf + $write + $common,
            ]],
            '/corporate/requests/{code}/participants' => ['post' => [
                'tags' => $T('Request'), 'operationId' => 'assignParticipant', 'summary' => 'Tetapkan peserta ke kursi (enrollment dibuat saat kelas dijadwalkan)',
                'description' => 'Isi `employment` (kode EMP-… karyawan aktif) atau data orang. Identity matching: member dengan email terverifikasi / nomor member dipakai ulang (tidak membuat akun kedua); kandidat yang belum pasti diundang lewat email (`invited`); data meragukan masuk review VMES (`review`, 202). Peserta yang sudah terdaftar di batch yang sama ditolak. Bila request sudah terjadwal, enrollment CORPORATE langsung dibuat (`seat.status = enrolled`).',
                'parameters' => array_merge([$path('code', 'Kode request')], $idem), 'requestBody' => $body('#/components/schemas/AssignmentInput'),
                'responses' => ['201' => $ok($ref('AssignmentResult'), 'Ditetapkan / diundang'), '202' => $ok($ref('AssignmentResult'), 'Masuk review identitas VMES')] + $nf + $write + $common,
            ]],
            '/corporate/requests/{code}/seats/{no}/release' => ['post' => [
                'tags' => $T('Request'), 'operationId' => 'releaseSeat', 'summary' => 'Batalkan satu peserta (hanya enrollment kursi itu yang berubah)',
                'parameters' => array_merge([$path('code', 'Kode request'), $path('no', 'Nomor kursi', 'integer')], $idem),
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['reason'], 'properties' => ['reason' => ['type' => 'string', 'maxLength' => 500]]]]]],
                'responses' => ['200' => $ok(['type' => 'object', 'properties' => ['request' => ['type' => 'string'], 'seat' => $ref('Seat'), 'dropped_enrollment_id' => ['type' => ['integer', 'null']]]])] + $nf + $write + $common,
            ]],
            '/corporate/employees' => [
                'get' => [
                    'tags' => $T('Karyawan'), 'operationId' => 'listEmployees', 'summary' => 'Direktori karyawan (employment relationship)',
                    'parameters' => array_merge([
                        $q('status', 'Default `active`', ['type' => 'string', 'enum' => ['active', 'inactive', 'all']]), $q('employee_id', 'Employee ID'),
                        $q('unit', 'Kode unit'), $q('q', 'Cari nama / employee ID'),
                    ], $page),
                    'responses' => ['200' => $list('Employee')] + $common,
                ],
                'post' => [
                    'tags' => $T('Karyawan'), 'operationId' => 'createEmployee', 'summary' => 'Catat karyawan untuk member VMES yang sudah ada (sinkron HRIS)',
                    'description' => 'API tidak membuat akun. Member harus teridentifikasi yakin lewat email terverifikasi atau nomor member; selain itu `422 identity_unresolved` (dengan `outcome`). Bila sudah aktif → `200` + `meta.already_active = true`.',
                    'parameters' => $idem, 'requestBody' => $body('#/components/schemas/EmployeeInput'),
                    'responses' => ['201' => $ok($ref('Employee'), 'Hubungan kerja dicatat'), '200' => $ok($ref('Employee'), 'Sudah aktif')] + $write + $common,
                ],
            ],
            '/corporate/employees/{code}' => ['get' => [
                'tags' => $T('Karyawan'), 'operationId' => 'getEmployee', 'summary' => 'Detail karyawan',
                'parameters' => [$path('code', 'Kode employment, mis. EMP-000001')],
                'responses' => ['200' => $ok($ref('Employee'))] + $nf + $common,
            ]],
            '/corporate/employees/{code}/end' => ['post' => [
                'tags' => $T('Karyawan'), 'operationId' => 'endEmployment', 'summary' => 'Akhiri hubungan kerja (resign/kontrak berakhir/pindah)',
                'description' => 'Employment menjadi `inactive`; akses Corporate Portal orang itu di organisasi ini dicabut. Member, enrollment lama, dan sertifikat tetap ada.',
                'parameters' => array_merge([$path('code', 'Kode employment')], $idem),
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['reason'], 'properties' => [
                    'reason' => ['type' => 'string', 'enum' => array_keys(EmploymentRelationship::END_REASONS)], 'end_date' => ['type' => 'string', 'format' => 'date'],
                ]]]]],
                'responses' => ['200' => $ok($ref('Employee'))] + $nf + $write + $common,
            ]],
            '/enrollments' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'listEnrollments', 'summary' => 'Enrollment yang disponsori organisasi',
                'parameters' => array_merge([$q('status', 'Status enrollment', ['type' => 'string', 'enum' => Enrollment::STATUSES])], $enrollmentFilters, $page),
                'responses' => ['200' => $list('Enrollment')] + $common,
            ]],
            '/enrollments/{id}' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'getEnrollment', 'summary' => 'Detail enrollment bersponsor',
                'parameters' => [$path('id', 'ID enrollment', 'integer')],
                'responses' => ['200' => $ok($ref('Enrollment'))] + $nf + $common,
            ]],
            '/progress' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'listProgress', 'summary' => 'Progres LMS & praktik per enrollment (maks. 50/halaman)',
                'parameters' => array_merge([$learning], $enrollmentFilters, $page),
                'responses' => ['200' => $list('ProgressItem')] + $common,
            ]],
            '/attendance' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'listAttendance', 'summary' => 'Kehadiran per sesi (maks. 50/halaman)',
                'parameters' => array_merge([$learning, $q('from', 'Sesi mulai tanggal', ['type' => 'string', 'format' => 'date']), $q('to', 'Sesi sampai tanggal', ['type' => 'string', 'format' => 'date'])], $enrollmentFilters, $page),
                'responses' => ['200' => $list('AttendanceItem')] + $common,
            ]],
            '/assessments' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'listAssessments', 'summary' => 'Nilai kuis/tugas, nilai akhir & penilaian kompetensi tervalidasi (maks. 50/halaman)',
                'parameters' => array_merge([$learning], $enrollmentFilters, $page),
                'responses' => ['200' => $list('AssessmentItem')] + $common,
            ]],
            '/certificates' => ['get' => [
                'tags' => $T('Enrollment'), 'operationId' => 'listSponsoredCertificates', 'summary' => 'Status sertifikat enrollment bersponsor',
                'description' => 'Nomor & tautan verifikasi hanya untuk sertifikat yang terbit. `updated_since` mengacu pada perubahan sertifikat.',
                'parameters' => array_merge([$q('status', 'Status sertifikat', ['type' => 'string', 'enum' => array_keys(Certificate::STATUSES)])], $enrollmentFilters, $page),
                'responses' => ['200' => $list('SponsoredCertificate')] + $common,
            ]],
            '/payments' => ['get' => [
                'tags' => $T('Keuangan & notifikasi'), 'operationId' => 'listPayments', 'summary' => 'Invoice organisasi & status pembayaran',
                'description' => 'Pembayaran manual (transfer/VA statis + bukti, diverifikasi finance VMES). Status invoice hanya berubah lewat verifikasi finance.',
                'parameters' => array_merge([$q('status', 'Status invoice', ['type' => 'string', 'enum' => array_keys(Invoice::STATUSES)])], $page),
                'responses' => ['200' => $list('Invoice')] + $common,
            ]],
            '/payments/{number}' => ['get' => [
                'tags' => $T('Keuangan & notifikasi'), 'operationId' => 'getPayment', 'summary' => 'Detail invoice + item + transaksi',
                'parameters' => [$path('number', 'Nomor invoice, mis. INV-202611-0001')],
                'responses' => ['200' => $ok($ref('InvoiceDetail'))] + $nf + $common,
            ]],
            '/notifications' => ['get' => [
                'tags' => $T('Keuangan & notifikasi'), 'operationId' => 'listNotificationDeliveries', 'summary' => 'Status pengiriman notifikasi ke pengguna perusahaan',
                'parameters' => array_merge([
                    $q('status', 'Status pengiriman', ['type' => 'string', 'enum' => array_keys(NotificationDelivery::STATUSES)]),
                    $q('channel', 'Kanal', ['type' => 'string', 'enum' => ['database', 'mail', 'webpush', 'whatsapp']]), $q('event', 'Kunci event, mis. request.status_changed'),
                ], $page),
                'responses' => ['200' => $list('NotificationDelivery')] + $common,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function schemas(): array
    {
        $s = fn (bool $nullable = false) => ['type' => $nullable ? ['string', 'null'] : 'string'];
        $i = fn (bool $nullable = false) => ['type' => $nullable ? ['integer', 'null'] : 'integer'];
        $n = fn (bool $nullable = false) => ['type' => $nullable ? ['number', 'null'] : 'number'];
        $b = ['type' => 'boolean'];
        $date = fn (bool $nullable = true) => ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date'];
        $dt = fn (bool $nullable = true) => ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'date-time'];
        $enum = fn (array $values, bool $nullable = false) => ['type' => $nullable ? ['string', 'null'] : 'string', 'enum' => $nullable ? [...$values, null] : $values];
        $obj = fn (array $props, bool $nullable = false) => ['type' => $nullable ? ['object', 'null'] : 'object', 'properties' => $props];
        $unit = $obj(['code' => $s(true), 'name' => $s()], true);
        $schedule = $obj(['code' => $s(), 'title' => $s(true), 'start_date' => $date(), 'end_date' => $date()], true);
        $participant = $obj(['name' => $s(true), 'member_number' => $s(true)]);
        $learningBase = ['enrollment_id' => $i(), 'status' => $enum(Enrollment::STATUSES), 'participant' => $participant, 'schedule' => $schedule];
        $prev = fn (array $values) => ['previous_status' => array_merge($enum($values, true), ['description' => 'Status sebelumnya (null bila baru dibuat)'])];

        $request = [
            'code' => $s(), 'status' => $enum(array_keys(TrainingRequest::STATUSES)), 'status_label' => $s(),
            'program' => $obj(['slug' => $s(), 'title' => $s()], true), 'delivery' => $enum(array_keys(TrainingRequest::DELIVERY)),
            'target_date' => $date(), 'location' => $s(true), 'unit' => $unit, 'seats' => $i(), 'seats_filled' => $i(), 'seats_invited' => $i(), 'schedule' => $schedule,
            'quote' => array_merge($obj(['price_per_seat' => $n(), 'discount' => $n(), 'total' => $n(), 'valid_until' => $date(), 'notes' => $s(true), 'currency' => ['type' => 'string', 'const' => 'IDR']], true),
                ['description' => 'Hanya untuk peran yang mengurus request/keuangan']),
            'submitted_at' => $dt(), 'quoted_at' => $dt(), 'approved_at' => $dt(), 'paid_at' => $dt(), 'scheduled_at' => $dt(), 'cancelled_at' => $dt(),
            'cancel_reason' => $s(true), 'created_at' => $dt(), 'updated_at' => $dt(),
        ];
        $enrollment = [
            'id' => $i(), 'type' => ['type' => 'string', 'const' => 'corporate'], 'status' => $enum(Enrollment::STATUSES),
            'participant' => $obj(['name' => $s(true), 'member_number' => $s(true), 'employee_id' => $s(true), 'employment_status' => $enum(['active', 'inactive', 'unknown'])]),
            'unit' => $unit, 'program' => $obj(['slug' => $s(), 'title' => $s()], true), 'schedule' => $schedule,
            'request' => $obj(['code' => $s(), 'seat_no' => $i()], true),
            'progress_visible' => array_merge($b, ['description' => 'false bila peran tidak boleh melihat progres atau peserta sudah keluar (kebijakan exit_access)']),
            'attendance_percent' => $i(true), 'final_score' => $n(true), 'certificate' => array_merge(['$ref' => '#/components/schemas/CertificateStatus'], ['description' => 'null bila belum ada']),
            'enrolled_at' => $dt(), 'completed_at' => $dt(), 'updated_at' => $dt(),
        ];
        $employee = [
            'code' => $s(), 'employee_id' => $s(true), 'name' => $s(true), 'member_number' => $s(true), 'position' => $s(true), 'unit' => $unit,
            'status' => $enum(array_keys(EmploymentRelationship::STATUSES)), 'start_date' => $date(), 'end_date' => $date(),
            'end_reason' => $enum(array_keys(EmploymentRelationship::END_REASONS), true), 'source' => $enum(array_keys(EmploymentRelationship::SOURCES)), 'updated_at' => $dt(),
        ];
        $invoice = [
            'number' => $s(), 'status' => $enum(array_keys(Invoice::STATUSES)), 'status_label' => $s(),
            'source' => $obj(['type' => $enum(['training_request', 'registration']), 'code' => $s(true)]),
            'subtotal' => $n(), 'discount' => $n(), 'tax_rate' => $n(), 'tax_amount' => $n(), 'total' => $n(), 'amount_paid' => $n(), 'amount_refunded' => $n(), 'outstanding' => $n(),
            'currency' => ['type' => 'string', 'const' => 'IDR'], 'issued_at' => $dt(), 'due_date' => $date(), 'paid_at' => $dt(), 'updated_at' => $dt(),
        ];

        return [
            'Organization' => $obj([
                'code' => $s(), 'name' => $s(), 'legal_name' => $s(), 'industry' => $s(true), 'city' => $s(true), 'status' => $s(),
                'requires_approval' => $b, 'exit_access' => $enum(['summary', 'full', 'none']),
                'acting_as' => $obj(['role' => $enum(array_keys(CorporateUser::ROLES)), 'role_label' => $s(), 'unit' => $unit, 'capabilities' => ['type' => 'array', 'items' => ['type' => 'string']]]),
                'units' => ['type' => 'array', 'items' => $obj(['code' => $s(true), 'name' => $s(), 'type' => $s(true)])],
            ]),
            'Analytics' => $obj([
                'organization' => $s(), 'generated_at' => $dt(false),
                'scope' => $obj(['unit' => $unit, 'funding' => ['type' => 'string', 'const' => 'corporate_sponsored']]),
                'kpi' => $obj([
                    'sponsored_participants' => $i(), 'active_enrollments' => $i(), 'completed_enrollments' => $i(), 'completion_rate' => $i(true), 'attendance_avg' => $i(true),
                    'certificates_issued' => $i(), 'active_employees' => $i(true), 'seats_bought' => $i(), 'seats_filled' => $i(),
                    'requests_by_status' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                ]),
                'definitions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ]),
            'TrainingRequest' => $obj($request),
            'TrainingRequestDetail' => ['allOf' => [['$ref' => '#/components/schemas/TrainingRequest'], $obj([
                'trainer_requirements' => $s(true), 'notes' => $s(true),
                'invoice' => $obj(['number' => $s(), 'status' => $enum(array_keys(Invoice::STATUSES)), 'total' => $n(), 'outstanding' => $n(), 'due_date' => $date()], true),
                'seat_list' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Seat']],
            ])]],
            'TrainingRequestInput' => ['type' => 'object', 'required' => ['program', 'seats', 'delivery'], 'properties' => [
                'program' => ['type' => 'string', 'description' => 'Slug atau kode program (GET /programs)'], 'seats' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                'delivery' => $enum(array_keys(TrainingRequest::DELIVERY)), 'target_date' => ['type' => 'string', 'format' => 'date'], 'location' => ['type' => 'string', 'maxLength' => 255],
                'unit' => ['type' => 'string', 'description' => 'Kode unit (default: unit pengguna yang diwakili)'], 'trainer_requirements' => ['type' => 'string', 'maxLength' => 2000],
                'notes' => ['type' => 'string', 'maxLength' => 2000], 'submit' => ['type' => 'boolean', 'default' => false],
            ]],
            'Seat' => $obj([
                'no' => $i(), 'status' => $enum(array_keys(RequestSeat::STATUSES)), 'status_label' => $s(), 'participant_name' => $s(true), 'participant_email' => $s(true),
                'unit' => $unit, 'assignment_method' => $s(true), 'enrollment_id' => $i(true), 'assigned_at' => $dt(),
            ]),
            'AssignmentInput' => ['type' => 'object', 'properties' => [
                'seat' => ['type' => 'integer', 'description' => 'Nomor kursi (default: kursi kosong pertama)'],
                'employment' => ['type' => 'string', 'description' => 'Kode EMP-… karyawan aktif; bila diisi, data orang diabaikan'],
                'name' => ['type' => 'string', 'description' => 'Wajib bila tanpa `employment`'], 'email' => ['type' => 'string', 'format' => 'email'], 'phone' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'], 'member_number' => ['type' => 'string'], 'unit' => ['type' => 'string', 'description' => 'Kode unit'], 'position' => ['type' => 'string'],
            ]],
            'AssignmentResult' => $obj(['result' => $enum(['assigned', 'invited', 'review']), 'message' => $s(), 'request' => $s(), 'seat' => ['$ref' => '#/components/schemas/Seat']]),
            'Enrollment' => $obj($enrollment),
            'CertificateStatus' => $obj(['status' => $enum(array_keys(Certificate::STATUSES)), 'number' => $s(true), 'issued_at' => $date(), 'valid_until' => $date(), 'verification_url' => $s(true)], true),
            'SponsoredCertificate' => $obj([
                'enrollment_id' => $i(), 'participant' => $participant, 'program' => $s(true), 'schedule' => $schedule,
                'status' => $enum(array_keys(Certificate::STATUSES)), 'number' => $s(true), 'issued_at' => $date(), 'valid_until' => $date(), 'verification_url' => $s(true),
            ]),
            'ProgressItem' => $obj($learningBase + [
                'learning' => $obj(['percent' => $i(), 'completed' => $i(), 'total' => $i(), 'last_seen_at' => $dt(),
                    'courses' => ['type' => 'array', 'items' => $obj(['slug' => $s(), 'title' => $s(), 'percent' => $i(true), 'completed' => $i(true), 'total' => $i(true)])]]),
                'practice' => $obj(['completed' => $i(), 'partial' => $i(), 'not_done' => $i(), 'logbooks' => $i()]),
                'logbook' => ['type' => 'array', 'items' => $obj(['date' => $date(false), 'topic' => $s(true), 'practice_status' => $s(true)])],
            ]),
            'AttendanceItem' => $obj($learningBase + [
                'attendance' => $obj(['percent' => $i(true), 'present' => $i(), 'held' => $i(), 'total' => $i(),
                    'sessions' => ['type' => 'array', 'items' => $obj(['session_no' => $i(), 'date' => $date(false), 'topic' => $s(true), 'status' => $enum(['present', 'late', 'absent', 'excused'], true), 'checked_at' => $dt()])]]),
            ]),
            'AssessmentItem' => $obj($learningBase + [
                'final_score' => $n(true),
                'scores' => $obj(['quiz_avg' => $i(true), 'quiz_done' => $i(), 'quiz_total' => $i(), 'assignment_avg' => $i(true), 'assignments_graded' => $i(), 'assignments_submitted' => $i(), 'assignments_total' => $i()]),
                'competency' => $obj(['status' => $s(), 'percent' => $n(true), 'is_competent' => ['type' => ['boolean', 'null']], 'threshold' => $n(true), 'rubric' => $s(true),
                    'assessed_at' => $dt(), 'validated_at' => $dt(),
                    'criteria' => ['type' => 'array', 'items' => $obj(['code' => $s(true), 'name' => $s(), 'competency' => $s(true), 'weight' => $n(true), 'max_score' => $i(true), 'score' => $n(true), 'level' => $s(true)])]], true),
            ]),
            'Employee' => $obj($employee),
            'EmployeeInput' => ['type' => 'object', 'required' => ['employee_id'], 'properties' => [
                'employee_id' => ['type' => 'string', 'maxLength' => 60], 'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Email akun member (wajib bila tanpa member_number)'],
                'member_number' => ['type' => 'string'], 'name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'position' => ['type' => 'string'],
                'unit' => ['type' => 'string', 'description' => 'Kode unit'], 'start_date' => ['type' => 'string', 'format' => 'date'],
            ]],
            'Invoice' => $obj($invoice),
            'InvoiceDetail' => ['allOf' => [['$ref' => '#/components/schemas/Invoice'], $obj([
                'items' => ['type' => 'array', 'items' => $obj(['description' => $s(), 'quantity' => $n(), 'unit_price' => $n(), 'amount' => $n()])],
                'transactions' => ['type' => 'array', 'items' => $obj(['code' => $s(), 'method' => $enum(array_keys(PaymentTransaction::METHODS)), 'amount' => $n(), 'amount_received' => $n(true),
                    'status' => $enum(array_keys(PaymentTransaction::STATUSES)), 'status_label' => $s(), 'transfer_date' => $date(), 'submitted_at' => $dt(), 'verified_at' => $dt()])],
            ])]],
            'NotificationDelivery' => $obj([
                'id' => $i(), 'event' => $s(true), 'channel' => $enum(['database', 'mail', 'webpush', 'whatsapp']), 'status' => $enum(array_keys(NotificationDelivery::STATUSES)), 'status_label' => $s(),
                'recipient_name' => $s(true), 'title' => $s(true), 'attempts' => $i(), 'scheduled_at' => $dt(), 'sent_at' => $dt(), 'delivered_at' => $dt(), 'read_at' => $dt(), 'failed_at' => $dt(), 'created_at' => $dt(),
            ]),
            // ---- payload webhook enterprise ----
            'TrainingRequestEvent' => $obj(array_diff_key($request, ['quote' => true]) + $prev(array_keys(TrainingRequest::STATUSES))),
            'EnrollmentEvent' => $obj($enrollment + $prev(Enrollment::STATUSES) + [
                'direction' => array_merge($enum(['in', 'out']), ['description' => 'Hanya `enrollment.sponsorship_transferred`. Untuk `out` payload hanya berisi `id`, `direction`, `transferred_at`.']),
                'transferred_at' => $dt(),
            ]),
            'EmployeeEvent' => $obj($employee + $prev(array_keys(EmploymentRelationship::STATUSES))),
            'InvoiceEvent' => $obj($invoice + $prev(array_keys(Invoice::STATUSES))),
        ];
    }

    /** Skema payload `data` per event webhook enterprise. */
    public static function webhookSchema(string $event): string
    {
        return '#/components/schemas/'.match (strstr($event, '.', true)) {
            'training_request' => 'TrainingRequestEvent',
            'enrollment' => 'EnrollmentEvent',
            'employee' => 'EmployeeEvent',
            'invoice' => 'InvoiceEvent',
            default => 'CertificateEvent',
        };
    }
}
