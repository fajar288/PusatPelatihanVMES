<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\AuditLog;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\RegistrationReceived;
use App\Notifications\RegistrationStatusChanged;
use App\Notifications\SystemNotification;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Rizky', 'email' => 'rizky@example.com', 'phone' => '0812', 'company' => 'PT A', 'consent' => true,
        ], $over);
    }

    public function test_guest_can_register_individual_and_gets_number_and_email(): void
    {
        $res = $this->postJson('/api/v1/registrations', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', 7500000)
            ->assertJsonPath('data.schedule.code', 'CCNA-2611');
        $this->assertSame('REG-202611-0001', $res->json('data.number'));

        $this->postJson('/api/v1/registrations', $this->payload(['email' => 'lain@example.com']))->assertCreated()->assertJsonPath('data.number', 'REG-202611-0002');

        $reg = Registration::query()->first();
        Notification::assertSentTo($reg, RegistrationReceived::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.created', 'auditable_id' => $reg->id]);
    }

    public function test_corporate_registration_with_participants(): void
    {
        $this->postJson('/api/v1/registrations', $this->payload([
            'type' => 'corporate', 'company' => 'PT Industri', 'participants_count' => 3,
            'participants' => [['name' => 'A'], ['name' => 'B', 'email' => 'b@x.id'], ['name' => 'C']],
        ]))->assertCreated()->assertJsonPath('data.participantsCount', 3)->assertJsonPath('data.total', 22500000)->assertJsonCount(3, 'data.participants');

        $this->postJson('/api/v1/registrations', $this->payload(['type' => 'corporate', 'company' => null]))->assertUnprocessable()->assertJsonValidationErrors(['company', 'participants_count']);
    }

    public function test_registration_rejected_when_schedule_closed_or_unknown(): void
    {
        $this->postJson('/api/v1/registrations', $this->payload(['schedule' => 'XXX']))->assertUnprocessable();
        TrainingSchedule::query()->where('code', 'CCNA-2611')->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/registrations', $this->payload())->assertUnprocessable();
    }

    public function test_member_registration_is_linked_and_listed(): void
    {
        $token = $this->postJson('/api/v1/auth/register', ['name' => 'Dewi', 'email' => 'dewi@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'phone' => '0813', 'consent' => true])->json('token');
        $this->withToken($token)->postJson('/api/v1/registrations', $this->payload(['email' => 'dewi@example.com', 'name' => 'Dewi']))->assertCreated();

        $list = $this->withToken($token)->getJson('/api/v1/me/registrations')->assertOk()->assertJsonCount(1, 'data');
        $number = $list->json('data.0.number');
        $this->assertNotNull(Registration::query()->where('registration_number', $number)->value('member_id'));

        $this->withToken($token)->getJson("/api/v1/me/registrations/$number")->assertOk()->assertJsonPath('data.cancellable', true);
        // orang lain tidak boleh melihat
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/me/registrations/$number")->assertForbidden();
    }

    public function test_status_flow_with_invoice_payment_proof_and_confirmation(): void
    {
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $member = User::factory()->create(['email' => 'peserta@example.com']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', $this->payload(['email' => 'peserta@example.com']))->assertCreated();
        $reg = Registration::query()->first();
        $service = app(RegistrationService::class);

        // transisi tidak sah
        try {
            $service->transition($reg, RegistrationStatus::Confirmed, $admin);
            $this->fail('transisi pending → confirmed seharusnya ditolak');
        } catch (ValidationException) {
        }

        $service->transition($reg, RegistrationStatus::UnderReview, $admin, 'Data lengkap');
        $service->transition($reg, RegistrationStatus::WaitingPayment, $admin);
        $this->assertDatabaseHas('invoices', ['registration_id' => $reg->id, 'total' => 7500000, 'status' => 'pending', 'invoice_number' => 'INV-202611-0001']);
        Notification::assertSentToTimes($reg, RegistrationStatusChanged::class, 2);

        // member unggah bukti → payment_verification
        $this->actingAs($member, 'sanctum')->post("/api/v1/me/registrations/{$reg->registration_number}/payment-proof", ['proof' => UploadedFile::fake()->image('bukti.jpg')])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.awaitingVerification', true);
        $this->assertSame('payment_verification', $reg->refresh()->status->value);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transactions.0.proofUploaded', true)->assertJsonPath('data.0.statusLabel', 'Menunggu verifikasi');

        // finance konfirmasi → kuota bertambah, member aktif
        $before = TrainingSchedule::query()->where('code', 'CCNA-2611')->value('registered_count');
        $service->transition($reg, RegistrationStatus::Confirmed, $admin);
        $this->assertSame($before + 1, TrainingSchedule::query()->where('code', 'CCNA-2611')->value('registered_count'));
        $this->assertSame('active', $member->refresh()->member->status);
        $this->assertSame(4, AuditLog::query()->where('action', 'registration.status_changed')->count());

        // sudah terkonfirmasi → pendaftar tidak bisa batal sendiri
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/registrations/{$reg->registration_number}/cancel")->assertForbidden();
        // notifikasi aplikasi ke akun member (PRD §5.12)
        Notification::assertSentTo($member, SystemNotification::class, fn (SystemNotification $n) => $n->key === 'registration.status_changed' && str_contains($n->title, 'Terkonfirmasi'));
    }

    public function test_owner_can_cancel_pending_registration(): void
    {
        $member = User::factory()->create(['email' => 'batal@example.com']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', $this->payload(['email' => 'batal@example.com']))->assertCreated();
        $number = Registration::query()->value('registration_number');
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/registrations/$number/cancel", ['reason' => 'Jadwal bentrok'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_registration_form_stores_documents_privately_with_original_names(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $res = $this->post('/api/v1/registrations', $this->payload([
            'documents' => [UploadedFile::fake()->create('KTP Rizky.pdf', 300, 'application/pdf'), UploadedFile::fake()->image('ijazah.jpg')],
        ]), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.documents.0.name', 'KTP Rizky.pdf')
            ->assertJsonPath('data.documents.1.name', 'ijazah.jpg')
            ->assertJsonPath('data.documentsAccepted', true);
        // respons tidak membocorkan path penyimpanan
        $this->assertStringNotContainsString('registrations/', $res->getContent());

        $reg = Registration::query()->first();
        $this->assertCount(2, $reg->documents);
        foreach ($reg->documents as $path) {
            $this->assertStringStartsWith('registrations/'.$reg->registration_number.'/', $path);
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('KTP Rizky.pdf', $reg->documentList()[0]['name']);
    }

    public function test_registration_documents_are_validated_and_paths_from_client_are_refused(): void
    {
        Storage::fake('local');
        $this->post('/api/v1/registrations', $this->payload(['documents' => [UploadedFile::fake()->create('skrip.exe', 10)]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('documents.0');
        $this->post('/api/v1/registrations', $this->payload(['documents' => [UploadedFile::fake()->create('besar.pdf', 5000, 'application/pdf')]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('documents.0');
        $six = array_map(fn ($i) => UploadedFile::fake()->create("d$i.pdf", 10, 'application/pdf'), range(1, 6));
        $this->post('/api/v1/registrations', $this->payload(['documents' => $six]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
        // path berkas dari klien (cara lama) tidak lagi diterima
        $this->postJson('/api/v1/registrations', $this->payload(['documents' => ['../../.env']]))->assertUnprocessable()->assertJsonValidationErrors('documents.0');
        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_owner_adds_documents_from_portal_until_confirmation(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['email' => 'dok@example.com']);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', $this->payload(['email' => 'dok@example.com']))->assertCreated();
        $reg = Registration::query()->first();
        $url = "/api/v1/me/registrations/{$reg->registration_number}/documents";

        $this->actingAs($member, 'sanctum')->post($url, ['documents' => [UploadedFile::fake()->create('surat-tugas.pdf', 100, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.documents.0.name', 'surat-tugas.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.documents_added', 'auditable_id' => $reg->id]);

        // orang lain tidak boleh menambah
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->post($url, ['documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertForbidden();

        // batas total 5 berkas termasuk yang sudah ada
        $five = array_map(fn ($i) => UploadedFile::fake()->create("d$i.pdf", 10, 'application/pdf'), range(1, 5));
        $this->actingAs($member, 'sanctum')->post($url, ['documents' => $five], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->assertCount(1, $reg->refresh()->documents);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // setelah terkonfirmasi tidak bisa menambah lagi
        $reg->forceFill(['status' => RegistrationStatus::Confirmed])->save();
        $this->actingAs($member, 'sanctum')->post($url, ['documents' => [UploadedFile::fake()->create('y.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/me/registrations/{$reg->registration_number}")->assertJsonPath('data.documentsAccepted', false);
    }

    public function test_roles_seeded_and_permissions_resolve(): void
    {
        $this->assertDatabaseCount('roles', 10);
        $finance = User::factory()->create(['role' => User::ROLE_FINANCE]);
        $this->assertTrue($finance->hasPermission('payments.verify'));
        $this->assertFalse($finance->hasPermission('cms.manage'));
        $student = User::factory()->create();
        $this->assertFalse($student->isAdmin());
    }
}
