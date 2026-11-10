<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\ScheduleChange;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Notifications\ScheduleChanged;
use App\Services\EnrollmentService;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TmsOperationsTest extends TestCase
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

    private function confirmedRegistration(User $member, array $over = []): Registration
    {
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', array_merge([
            'schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $member->name, 'email' => $member->email, 'phone' => '0812', 'consent' => true,
        ], $over))->assertCreated();
        $reg = Registration::query()->latest('id')->first();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $admin);
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $admin);

        return $reg->refresh();
    }

    public function test_confirmation_creates_enrollment_and_portal_lists_it_with_sessions_and_attendance(): void
    {
        $member = User::factory()->create(['email' => 'peserta@example.com']);
        $reg = $this->confirmedRegistration($member);
        $this->assertDatabaseHas('enrollments', ['registration_id' => $reg->id, 'participant_email' => 'peserta@example.com', 'status' => 'enrolled']);
        $enrollment = Enrollment::query()->first();
        $this->assertNotNull($enrollment->member_id);

        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $trainer = Trainer::query()->create(['name' => 'Andi Pratama', 'slug' => 'andi-pratama']);
        $schedule->trainers()->attach($trainer->id, ['role' => 'lead']);
        $s1 = ClassSession::query()->create(['schedule_id' => $schedule->id, 'session_no' => 1, 'date' => '2026-11-17', 'topic' => 'Network fundamentals']);
        ClassSession::query()->create(['schedule_id' => $schedule->id, 'session_no' => 2, 'date' => '2026-11-18']);
        Attendance::query()->create(['session_id' => $s1->id, 'enrollment_id' => $enrollment->id, 'status' => 'present']);

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/enrollments')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.schedule.code', 'CCNA-2611')
            ->assertJsonPath('data.0.schedule.trainers.0.name', 'Andi Pratama')
            ->assertJsonCount(2, 'data.0.sessions')
            ->assertJsonPath('data.0.sessions.0.attendanceLabel', 'Hadir')
            ->assertJsonPath('data.0.sessions.1.attendance', null);

        // konfirmasi ulang tidak menggandakan enrollment
        app(EnrollmentService::class)->fromRegistration($reg);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_corporate_confirmation_enrolls_each_participant(): void
    {
        $member = User::factory()->create(['email' => 'pic@example.com']);
        $this->confirmedRegistration($member, ['type' => 'corporate', 'company' => 'PT X', 'participants_count' => 2, 'participants' => [['name' => 'A', 'email' => 'a@x.id'], ['name' => 'B']]]);
        $this->assertDatabaseCount('enrollments', 2);
        $this->assertDatabaseHas('enrollments', ['participant_name' => 'A', 'participant_email' => 'a@x.id']);
    }

    public function test_schedule_change_is_logged_and_registrants_notified(): void
    {
        $member = User::factory()->create(['email' => 'aktif@example.com']);
        $this->confirmedRegistration($member);
        $cancelled = User::factory()->create(['email' => 'batal@example.com']);
        $this->actingAs($cancelled, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Batal', 'email' => 'batal@example.com', 'phone' => '1', 'consent' => true])->assertCreated();
        $regCancelled = Registration::query()->where('email', 'batal@example.com')->first();
        app(RegistrationService::class)->transition($regCancelled, RegistrationStatus::Cancelled);
        Notification::fake();

        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $schedule->changeReason = 'Trainer berhalangan';
        $schedule->update(['start_date' => '2026-11-24', 'end_date' => '2026-11-28', 'location' => 'Lab Batam']);

        $change = ScheduleChange::query()->first();
        $this->assertSame('Trainer berhalangan', $change->reason);
        $this->assertSame('2026-11-17', $change->before['start_date']);
        $this->assertSame('2026-11-24', $change->after['start_date']);
        $this->assertSame(1, $change->notified_count);
        Notification::assertSentTo(Registration::query()->where('email', 'aktif@example.com')->first(), ScheduleChanged::class);
        Notification::assertNotSentTo($regCancelled, ScheduleChanged::class);

        // perubahan non-tracked (mis. catatan) tidak membuat riwayat
        $schedule->update(['notes' => 'x']);
        $this->assertDatabaseCount('schedule_changes', 1);
    }

    public function test_waitlist_join_is_idempotent_and_positioned(): void
    {
        $this->postJson('/api/v1/schedules/LNX-2701/waitlist', ['name' => 'Tunggu', 'email' => 'tunggu@example.com', 'phone' => '1'])
            ->assertCreated()->assertJsonPath('data.position', 1);
        $this->postJson('/api/v1/schedules/LNX-2701/waitlist', ['name' => 'Tunggu', 'email' => 'tunggu@example.com'])
            ->assertOk()->assertJsonPath('data.position', 1);
        $this->postJson('/api/v1/schedules/LNX-2701/waitlist', ['name' => 'Kedua', 'email' => 'kedua@example.com'])
            ->assertCreated()->assertJsonPath('data.position', 2);
        $this->postJson('/api/v1/schedules/XXX/waitlist', ['name' => 'X', 'email' => 'x@example.com'])->assertNotFound();
        $this->assertDatabaseCount('waitlists', 2);
    }

    public function test_package_detail_exposes_program_type_and_completion_rule(): void
    {
        $this->getJson('/api/v1/packages/cisco-ccna')->assertOk()
            ->assertJsonPath('data.programType', 'regular')
            ->assertJsonPath('data.certificateType', 'participation')
            ->assertJsonPath('data.completionRule.min_attendance_pct', 80);
    }
}
