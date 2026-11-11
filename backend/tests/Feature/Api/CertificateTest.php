<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\CertificateIssued;
use App\Services\CertificateService;
use App\Services\CompletionService;
use App\Services\MonitoringService;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Phase 4 — aturan kelulusan, sertifikat (draf → validasi → terbit → cabut/ganti), PDF + QR, verifikasi publik, dashboard alumni. */
class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $schedule;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 09:00:00'); // kelas CCNA-2611 (17–21 Nov 2026) sudah selesai
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(config('filesystems.default'));
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        foreach ([1 => '2026-11-17', 2 => '2026-11-18', 3 => '2026-11-19', 4 => '2026-11-20', 5 => '2026-11-21'] as $no => $date) {
            ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => $no, 'date' => $date, 'topic' => "Sesi $no"]);
        }
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Enrollment} */
    private function enrolledMember(string $email = 'peserta@example.com', int $presentSessions = 5): array
    {
        $member = User::factory()->create(['email' => $email, 'name' => 'Budi Santoso']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $member->name, 'email' => $email, 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->latest('id')->first();
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $this->admin);
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $this->admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $this->admin);
        $this->app['auth']->forgetGuards();
        $enrollment = Enrollment::query()->where('registration_id', $reg->id)->first();
        foreach ($this->schedule->sessions()->get()->take($presentSessions) as $s) {
            Attendance::query()->create(['session_id' => $s->id, 'enrollment_id' => $enrollment->id, 'status' => 'present']);
        }

        return [$member, $enrollment];
    }

    public function test_member_status_follows_enrollment_and_completion_rule(): void
    {
        [$user, $enrollment] = $this->enrolledMember(presentSessions: 3); // 60% < 80%
        $user = $user->fresh();
        $this->assertSame('active', $user->member->refresh()->status);

        $completion = app(CompletionService::class);
        $eval = $completion->evaluate($enrollment);
        $this->assertFalse($eval['eligible']);
        $this->assertFalse(collect($eval['checks'])->firstWhere('key', 'attendance')['ok']);

        $completion->complete($enrollment, $this->admin);
        $this->assertSame('failed', $enrollment->refresh()->status);
        $this->assertSame('registered', $user->member->refresh()->status);

        // paksa lulus → alumni + peran alumni
        $completion->complete($enrollment, $this->admin, pass: true, finalScore: 75, note: 'Dispensasi');
        $this->assertSame('completed', $enrollment->refresh()->status);
        $this->assertSame('alumni', $user->member->refresh()->status);
        $this->assertNotNull($user->member->alumni_since);
        $this->assertSame(User::ROLE_ALUMNI, $user->refresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'enrollment.completed', 'auditable_id' => $enrollment->id]);
    }

    public function test_certificate_lifecycle_pdf_verification_and_member_download(): void
    {
        [$user, $enrollment] = $this->enrolledMember();
        $svc = app(CertificateService::class);

        // belum lulus → tidak layak, tanpa paksa gagal
        $this->assertFalse($svc->eligibility($enrollment)['eligible']);
        try {
            $svc->createDraft($enrollment, $this->admin);
            $this->fail('Draf tidak boleh dibuat sebelum lulus.');
        } catch (ValidationException) {
        }

        app(CompletionService::class)->complete($enrollment, $this->admin);
        $this->assertSame('completed', $enrollment->refresh()->status);
        $this->assertTrue($svc->eligibility($enrollment)['eligible']);

        $cert = $svc->createDraft($enrollment, $this->admin);
        $this->assertSame('draft', $cert->status);
        $this->assertMatchesRegularExpression('/^PPTI-PST-2026\d{2}-00001$/', $cert->number);
        $this->assertSame('Budi Santoso', $cert->recipient_name);
        $this->assertSame('CCNA-2611', $cert->class_code);
        $this->assertNotEmpty($cert->competencies);
        $this->assertTrue(CertificateTemplate::query()->where('type', 'participation')->where('is_default', true)->exists());

        // sertifikat kedua untuk enrollment yang sama ditolak
        try {
            $svc->createDraft($enrollment, $this->admin);
            $this->fail('Sertifikat aktif ganda tidak boleh.');
        } catch (ValidationException) {
        }

        // verifikasi publik sebelum terbit: nama disembunyikan
        $this->getJson('/api/v1/verify/'.$cert->number)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.recipientName', null)->assertJsonPath('data.valid', false);
        $this->getJson('/api/v1/verify/PPTI-XXX-000000-99999')->assertNotFound();

        // draf tidak tampil di portal member
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/certificates')->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();

        // koordinator ajukan → pejabat berwenang terbitkan
        $svc->submit($cert, $this->admin);
        $this->assertSame('pending_validation', $cert->refresh()->status);
        $coordinator = User::factory()->create(['role' => User::ROLE_TRAINING_COORDINATOR]);
        try {
            $svc->issue($cert, $coordinator);
            $this->fail('Koordinator tidak berwenang menerbitkan.');
        } catch (ValidationException) {
        }
        $svc->issue($cert, $this->admin);
        $cert->refresh();
        $this->assertSame('issued', $cert->status);
        $this->assertNotNull($cert->pdf_path);
        Storage::disk(config('filesystems.default'))->assertExists($cert->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk(config('filesystems.default'))->get($cert->pdf_path));
        Notification::assertSentOnDemand(CertificateIssued::class, fn ($n, $channels, $notifiable) => array_key_exists('peserta@example.com', $notifiable->routes['mail']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.issued', 'auditable_type' => Certificate::class, 'auditable_id' => $cert->id]);

        // verifikasi publik (AC-10) + kode QR
        $this->getJson('/api/v1/verify/'.$cert->number.'?k='.$cert->verification_code)->assertOk()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.valid', true)->assertJsonPath('data.codeMatches', true)
            ->assertJsonPath('data.recipientName', 'Budi Santoso')->assertJsonPath('data.typeLabel', 'Sertifikat keikutsertaan');
        $this->getJson('/api/v1/verify/'.$cert->number.'?k=SALAH')->assertOk()->assertJsonPath('data.codeMatches', false);

        // portal member: daftar, detail, unduh PDF, enrollment menampilkan sertifikat
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/certificates')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $cert->number)->assertJsonPath('data.0.downloadable', true);
        $this->getJson('/api/v1/me/certificates/'.$cert->number)->assertOk()->assertJsonPath('data.history.0.action', 'certificate.issued');
        $res = $this->get('/api/v1/me/certificates/'.$cert->number.'/pdf');
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->getJson('/api/v1/me/enrollments')->assertOk()->assertJsonPath('data.0.certificate.number', $cert->number)->assertJsonPath('data.0.certificate.downloadable', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.downloaded', 'auditable_id' => $cert->id]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.member.status', 'alumni');

        // akun lain tidak bisa mengakses
        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/me/certificates/'.$cert->number.'/pdf')->assertNotFound();
        $this->app['auth']->forgetGuards();

        // ganti (salah nama): lama → replaced, baru draf dengan nomor baru; lalu cabut yang baru setelah terbit
        $new = $svc->replace($cert, $this->admin, 'Nama salah ketik', ['recipient_name' => 'Budi Santoso, S.Kom.']);
        $this->assertSame('replaced', $cert->refresh()->status);
        $this->assertSame($new->id, $cert->replaced_by_id);
        $this->assertSame('draft', $new->status);
        $this->assertNotSame($cert->number, $new->number);
        $this->assertSame('Budi Santoso, S.Kom.', $new->recipient_name);
        $this->getJson('/api/v1/verify/'.$cert->number)->assertOk()->assertJsonPath('data.status', 'replaced')->assertJsonPath('data.replacedBy', $new->number);
        $this->actingAs($user, 'sanctum')->get('/api/v1/me/certificates/'.$cert->number.'/pdf')->assertStatus(410);
        $this->app['auth']->forgetGuards();

        $svc->issue($new, $this->admin);
        $svc->revoke($new->refresh(), $this->admin, 'Ditemukan pelanggaran');
        $this->assertSame('revoked', $new->refresh()->status);
        $this->getJson('/api/v1/verify/'.$new->number)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.valid', false);
        $this->assertSame(2, AuditLog::query()->where('action', 'certificate.issued')->count());
    }

    public function test_competency_certificate_requires_validated_competent_assessment_and_bulk_generation(): void
    {
        [, $e1] = $this->enrolledMember('a@example.com');
        [, $e2] = $this->enrolledMember('b@example.com', presentSessions: 2);
        $this->schedule->package->update(['certificate_type' => 'competency']);
        app(CompletionService::class)->complete($e1, $this->admin);
        app(CompletionService::class)->complete($e2, $this->admin);
        $svc = app(CertificateService::class);

        $check = $svc->eligibility($e1->refresh());
        $this->assertFalse($check['eligible']);
        $this->assertStringContainsString('kompetensi', implode(' ', $check['reasons']));

        // penilaian tervalidasi & kompeten → layak
        $mon = app(MonitoringService::class);
        $a = $mon->assessmentFor($e1);
        $mon->saveAssessment($e1, $a->rubric->criteria->map(fn ($c) => ['criterion_id' => $c->id, 'score' => $c->max_score])->all(), 'Baik', null, $this->admin);
        $mon->validateAssessment($a->refresh(), $this->admin);
        $this->assertTrue($svc->eligibility($e1->refresh())['eligible']);

        $result = $svc->createDraftsForSchedule($this->schedule, $this->admin);
        $this->assertCount(1, $result['created']);
        $this->assertCount(1, $result['skipped']);
        $cert = $result['created'][0];
        $this->assertSame('competency', $cert->type);
        $this->assertStringStartsWith('PPTI-KMP-', $cert->number);
        $this->assertSame('Kompeten', $cert->predicate);
        $this->assertNotNull($cert->valid_until); // template kompetensi bawaan 36 bulan

        // paksa untuk yang tidak layak, tercatat di audit
        $forced = $svc->createDraft($e2->refresh(), $this->admin, [], true, 'Kebijakan khusus');
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.created', 'auditable_id' => $forced->id]);
        $this->assertTrue(AuditLog::query()->where('auditable_id', $forced->id)->where('action', 'certificate.created')->first()->after['forced']);
    }

    public function test_admin_pdf_routes_and_template_preview(): void
    {
        [, $e] = $this->enrolledMember();
        app(CompletionService::class)->complete($e, $this->admin);
        $cert = app(CertificateService::class)->createDraft($e, $this->admin);
        $template = CertificateTemplate::query()->first();

        $this->get('/admin/certificates/'.$cert->id.'/pdf')->assertRedirect();
        $this->actingAs($this->admin, 'web')->get('/admin/certificates/'.$cert->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin, 'web')->get('/admin/certificate-templates/'.$template->id.'/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin, 'web')->get('/admin/certificates/'.$cert->id.'/edit')->assertOk();
        $this->actingAs($this->admin, 'web')->get('/admin/certificate-templates/'.$template->id.'/edit')->assertOk();
        $this->actingAs($this->admin, 'web')->get('/admin/training-schedules/'.$this->schedule->code.'/edit')->assertOk();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($student, 'web')->get('/admin/certificates/'.$cert->id.'/pdf')->assertForbidden();
    }
}
