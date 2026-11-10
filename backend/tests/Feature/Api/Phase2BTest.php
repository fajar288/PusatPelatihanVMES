<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\PromoCode;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\RegistrationService;
use App\Services\ReportService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase2BTest extends TestCase
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

    private function registration(array $over = []): Registration
    {
        $this->postJson('/api/v1/registrations', array_merge(['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => 'Peserta', 'email' => 'p'.rand().'@example.com', 'phone' => '1', 'consent' => true], $over))->assertCreated();

        return Registration::query()->latest('id')->first();
    }

    public function test_promo_code_discount_and_installments(): void
    {
        PromoCode::query()->create(['code' => 'HEMAT10', 'name' => 'Diskon 10%', 'type' => 'percent', 'value' => 10, 'max_discount' => 500000, 'max_uses' => 1]);
        $reg = $this->registration();
        $finance = app(FinanceService::class);

        $finance->applyPromo($reg, 'hemat10');
        $this->assertSame(500000.0, (float) $reg->refresh()->discount);
        $this->assertSame(7000000.0, (float) $reg->total);
        $this->assertSame(1, PromoCode::query()->first()->used_count);

        // kuota promo habis untuk pendaftaran lain
        $other = $this->registration();
        try {
            $finance->applyPromo($other, 'HEMAT10');
            $this->fail('promo seharusnya habis');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('habis', $e->getMessage());
        }

        // termin 3x dari total 7.000.000 → 2.333.333 + 2.333.333 + 2.333.334, jatuh tempo berjarak 30 hari
        $payments = $finance->createInstallments($reg, 3, 30, '2026-11-15');
        $this->assertCount(3, $payments);
        $this->assertSame(7000000.0, array_sum(array_map(fn ($p) => (float) $p->amount, $payments)));
        $this->assertSame('2026-12-15', $payments[1]->due_date->toDateString());
        $this->assertSame('Termin 3 dari 3', $payments[2]->description);
        $this->assertSame(3, $reg->payments()->count());

        // ringkasan pendapatan
        $payments[0]->update(['status' => 'verified', 'verified_at' => now()]);
        $sum = $finance->revenueSummary();
        $this->assertSame((float) $payments[0]->amount, $sum['verified']);
        $this->assertSame(['2026-11' => (float) $payments[0]->amount], $sum['by_month']);
        $this->assertGreaterThan(0, $sum['outstanding']);
    }

    public function test_reports_datasets_and_export(): void
    {
        $reg = $this->registration();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        app(RegistrationService::class)->transition($reg, RegistrationStatus::WaitingPayment, $admin);

        $reports = app(ReportService::class);
        $ov = $reports->overview();
        $this->assertSame(1, $ov['registrations_total']);
        $this->assertSame(1, $ov['registrations_by_status']['waiting_payment']['count']);
        foreach (array_keys(ReportService::TYPES) as $type) {
            $ds = $reports->dataset($type);
            $this->assertNotEmpty($ds['headers']);
            foreach ($ds['rows'] as $row) {
                $this->assertCount(count($ds['headers']), $row);
            }
        }

        // tamu dulu (guard menyimpan user antar-request dalam satu tes)
        $this->get('/admin/export/registrations.csv')->assertRedirect('/admin/login');
        $csv = $this->actingAs($admin)->get('/admin/export/registrations.csv');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($reg->registration_number, $csv->streamedContent());
        $this->actingAs($admin)->get('/admin/export/payments.xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($admin)->get('/admin/export/unknown.csv')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/admin/export/registrations.csv')->assertForbidden();
    }

    public function test_trainer_portal_classes_and_attendance(): void
    {
        $trainerUser = User::factory()->create(['role' => User::ROLE_TRAINER]);
        $trainer = Trainer::query()->create(['name' => 'Andi', 'slug' => 'andi', 'user_id' => $trainerUser->id]);
        $schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $schedule->trainers()->attach($trainer->id, ['role' => 'lead']);
        $s1 = ClassSession::query()->create(['schedule_id' => $schedule->id, 'session_no' => 1, 'date' => '2026-11-17']);
        $e1 = Enrollment::query()->create(['schedule_id' => $schedule->id, 'participant_name' => 'A', 'enrolled_at' => now()]);
        $e2 = Enrollment::query()->create(['schedule_id' => $schedule->id, 'participant_name' => 'B', 'enrolled_at' => now()]);

        // bukan trainer
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/trainer/classes')->assertForbidden();

        $this->actingAs($trainerUser, 'sanctum')->getJson('/api/v1/trainer/classes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CCNA-2611')->assertJsonPath('data.0.participantsCount', 2)->assertJsonPath('data.0.myRole', 'lead');

        $this->actingAs($trainerUser, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/sessions/1/attendance', [
            'marks' => [['enrollment_id' => $e1->id, 'status' => 'present'], ['enrollment_id' => $e2->id, 'status' => 'absent', 'note' => 'sakit']],
        ])->assertOk()->assertJsonPath('marked', 2);
        // ubah lagi → update, bukan duplikat
        $this->actingAs($trainerUser, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/sessions/1/attendance', ['marks' => [['enrollment_id' => $e2->id, 'status' => 'late']]])->assertOk();
        $this->assertDatabaseCount('attendances', 2);

        $this->actingAs($trainerUser, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611')->assertOk()
            ->assertJsonCount(2, 'data.participants')
            ->assertJsonPath('data.participants.1.attendance.1', 'late')
            ->assertJsonPath('data.sessions.0.marked', 2);

        // kelas lain yang tidak ditugaskan
        $this->actingAs($trainerUser, 'sanctum')->getJson('/api/v1/trainer/classes/MTCNA-2611')->assertForbidden();
        // enrollment dari kelas lain ditolak validasi
        $this->actingAs($trainerUser, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/sessions/1/attendance', ['marks' => [['enrollment_id' => 999, 'status' => 'present']]])->assertUnprocessable();

        $this->actingAs($trainerUser, 'sanctum')->patchJson('/api/v1/trainer/profile', ['title' => 'Lead Trainer', 'expertise' => ['Cisco', 'Routing']])->assertOk()
            ->assertJsonPath('data.title', 'Lead Trainer')->assertJsonCount(2, 'data.expertise');
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.marked']);
    }
}
