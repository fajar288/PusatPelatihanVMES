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
        $this->assertDatabaseHas('payments', ['registration_id' => $reg->id, 'amount' => 7500000, 'status' => 'unpaid', 'invoice_number' => 'INV-202611-0001']);
        Notification::assertSentToTimes($reg, RegistrationStatusChanged::class, 2);

        // member unggah bukti → payment_verification
        $this->actingAs($member, 'sanctum')->post("/api/v1/me/registrations/{$reg->registration_number}/payment-proof", ['proof' => UploadedFile::fake()->image('bukti.jpg')])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame('payment_verification', $reg->refresh()->status->value);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.proofUploaded', true);

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
