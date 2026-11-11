<?php

namespace Tests\Feature\Api;

use App\Enums\RegistrationStatus;
use App\Models\AssessmentRubric;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\CompetencyAssessment;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingLogbook;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 3 tahap 2 — logbook per pertemuan, rubrik & penilaian kompetensi, kunci + audit, progress overview. */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $schedule;

    private ClassSession $s1;

    private ClassSession $s2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00'); // kelas CCNA-2611 berlangsung 17–21 Nov 2026
        $this->seed([RolesSeeder::class, CmsSeeder::class]);
        Notification::fake();
        Storage::fake(config('filesystems.default'));
        $this->schedule = TrainingSchedule::query()->where('code', 'CCNA-2611')->first();
        $this->s1 = ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => 1, 'date' => '2026-11-17', 'topic' => 'Dasar jaringan']);
        $this->s2 = ClassSession::query()->create(['schedule_id' => $this->schedule->id, 'session_no' => 2, 'date' => '2026-11-18', 'topic' => 'Routing']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Enrollment} */
    private function enrolledMember(string $email = 'peserta@example.com'): array
    {
        $member = User::factory()->create(['email' => $email]);
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/registrations', ['schedule' => 'CCNA-2611', 'type' => 'individual', 'name' => $member->name, 'email' => $email, 'phone' => '0812', 'consent' => true])->assertCreated();
        $reg = Registration::query()->latest('id')->first();
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(RegistrationService::class);
        $svc->transition($reg, RegistrationStatus::WaitingPayment, $admin);
        $svc->transition($reg, RegistrationStatus::PaymentVerification, $admin);
        $svc->transition($reg, RegistrationStatus::Confirmed, $admin);
        $this->app['auth']->forgetGuards();

        return [$member, Enrollment::query()->where('registration_id', $reg->id)->first()];
    }

    private function trainerUser(bool $assigned = true, string $email = 'trainer@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => User::ROLE_TRAINER]);
        $trainer = Trainer::query()->create(['name' => 'Andi', 'slug' => 'andi-'.$user->id, 'user_id' => $user->id]);
        if ($assigned) {
            $this->schedule->trainers()->attach($trainer->id, ['role' => 'lead']);
        }

        return $user;
    }

    public function test_trainer_logbook_lifecycle_with_lock_and_audit(): void
    {
        [, $enrollment] = $this->enrolledMember();
        $trainer = $this->trainerUser();

        $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/logbooks')->assertOk()
            ->assertJsonCount(2, 'data.sessions')->assertJsonCount(1, 'data.participants')->assertJsonCount(0, 'data.logbooks');

        $payload = [
            'session_id' => $this->s1->id, 'date' => '2026-11-17', 'topic' => 'Dasar jaringan & pengalamatan IP',
            'activities' => 'Ceramah OSI, demo subnetting', 'competencies' => ['Model OSI', 'Subnetting IPv4'], 'practice' => 'Konfigurasi IP di Packet Tracer',
            'observations' => 'Peserta antusias', 'obstacles' => 'Proyektor sempat mati', 'notes' => 'Ulangi subnetting di sesi 2',
            'entries' => [['enrollment_id' => $enrollment->id, 'practice_status' => 'partial', 'observation' => 'Masih bingung CIDR']],
            'docs' => [UploadedFile::fake()->image('foto-kelas.jpg')],
        ];
        $r = $this->actingAs($trainer, 'sanctum')->post('/api/v1/trainer/classes/CCNA-2611/logbooks', $payload, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.sessionNo', 1)->assertJsonCount(2, 'data.competencies')->assertJsonCount(1, 'data.documentation')
            ->assertJsonPath('data.entries.0.practiceStatus', 'partial');
        $id = $r->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logbook.created', 'auditable_id' => $id]);
        $this->actingAs($trainer, 'sanctum')->get("/api/v1/trainer/logbooks/$id/docs/0")->assertOk()->assertDownload('foto-kelas.jpg');

        // sesi yang sama tidak boleh punya dua logbook
        $this->actingAs($trainer, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/logbooks', ['session_id' => $this->s1->id, 'date' => '2026-11-17', 'topic' => 'Duplikat'])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');

        // ubah draf: topik + status praktik peserta + hapus dokumentasi
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/logbooks/$id", ['date' => '2026-11-17', 'topic' => 'Dasar jaringan (revisi)', 'session_id' => $this->s1->id,
            'entries' => [['enrollment_id' => $enrollment->id, 'practice_status' => 'completed', 'observation' => 'Sudah paham CIDR']], 'remove_docs' => [0]])
            ->assertOk()->assertJsonPath('data.topic', 'Dasar jaringan (revisi)')->assertJsonPath('data.entries.0.practiceStatus', 'completed')->assertJsonCount(0, 'data.documentation');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logbook.updated', 'auditable_id' => $id]);

        // validasi → terkunci; trainer tidak bisa mengubah lagi
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/logbooks/$id/validate")->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.isLocked', true);
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/logbooks/$id", ['date' => '2026-11-17', 'topic' => 'Coba ubah'])
            ->assertUnprocessable()->assertJsonFragment(['logbook' => [MonitoringService::LOCKED_MESSAGE]]);
        $this->assertSame('Dasar jaringan (revisi)', TrainingLogbook::query()->find($id)->topic);

        // admin membuka kunci dengan alasan → tercatat; perubahan sesudahnya tercatat sebagai pasca-validasi
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(MonitoringService::class);
        $logbook = $svc->unlockLogbook(TrainingLogbook::query()->find($id), $admin, 'Salah tanggal');
        $this->assertFalse($logbook->is_locked);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logbook.unlocked', 'auditable_id' => $id, 'actor_id' => $admin->id]);
        $this->assertStringContainsString('Salah tanggal', AuditLog::query()->where('action', 'logbook.unlocked')->first()->getRawOriginal('after'));
        $svc->validateLogbook($logbook, $admin);
        $svc->saveLogbook($this->schedule, $logbook->refresh(), ['date' => '2026-11-17', 'topic' => 'Diubah admin'], [], [], [], $admin, null, true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logbook.updated_after_validation', 'auditable_id' => $id]);

        // trainer yang tidak ditugaskan / orang luar ditolak
        $other = $this->trainerUser(false, 'lain@example.com');
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/logbooks')->assertForbidden();
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/trainer/logbooks/$id/validate")->assertForbidden();
    }

    public function test_assessment_uses_program_rubric_and_locks_after_validation(): void
    {
        [, $enrollment] = $this->enrolledMember();
        $trainer = $this->trainerUser();

        // rubrik bawaan dibuat otomatis untuk program (6 kriteria PRD §5.9)
        $r = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/assessments')->assertOk()
            ->assertJsonCount(6, 'data.rubric.criteria')->assertJsonCount(1, 'data.participants')->assertJsonPath('data.participants.0.assessment.status', 'draft')->assertJsonPath('data.participants.0.assessment.percent', null);
        $this->assertSame(1, AssessmentRubric::query()->where('package_id', $this->schedule->package_id)->count());
        $criteria = collect($r->json('data.rubric.criteria'));

        // simpan sebagian skor → total dari kriteria yang terisi; validasi ditolak bila belum lengkap
        $scores = $criteria->take(5)->map(fn ($c) => ['criterion_id' => $c['id'], 'score' => 3])->values()->all();
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => $scores, 'summary' => 'Cukup baik'])
            ->assertOk()->assertJsonPath('data.percent', 75)->assertJsonPath('data.isCompetent', true)->assertJsonPath('data.criteria.0.levelLabel', 'Kompeten');
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => $scores, 'validate' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('assessment');
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => [['criterion_id' => $criteria->first()['id'], 'score' => 9]]])
            ->assertUnprocessable();

        // lengkapi (kriteria ke-6 = 1) → 16/24 = 66,67% < 70 → belum kompeten; validasi mengunci
        $all = array_merge($scores, [['criterion_id' => $criteria->last()['id'], 'score' => 1, 'note' => 'Perlu pendampingan']]);
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => $all, 'summary' => 'Perlu latihan mandiri', 'validate' => true])
            ->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.isLocked', true)->assertJsonPath('data.percent', 66.67)->assertJsonPath('data.isCompetent', false);
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => $scores])
            ->assertUnprocessable()->assertJsonFragment(['assessment' => [MonitoringService::LOCKED_MESSAGE]]);
        $a = CompetencyAssessment::query()->where('enrollment_id', $enrollment->id)->first();
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.validated', 'auditable_id' => $a->id]);

        // admin buka kunci + ubah → audit pasca-validasi
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $svc = app(MonitoringService::class);
        $svc->unlockAssessment($a, $admin, 'Koreksi skor praktik');
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.unlocked', 'auditable_id' => $a->id]);
        $svc->validateAssessment($a->refresh(), $admin);
        $svc->saveAssessment($enrollment->fresh(), [['criterion_id' => $criteria->last()['id'], 'score' => 3]], null, null, $admin, null, true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.updated_after_validation', 'auditable_id' => $a->id]);
        $this->assertEquals(75, (float) $a->refresh()->total_percent);
    }

    public function test_progress_overview_for_trainer_and_participant_respects_validation(): void
    {
        [$member, $enrollment] = $this->enrolledMember();
        $trainer = $this->trainerUser();
        $this->actingAs($trainer, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/sessions/1/attendance', ['marks' => [['enrollment_id' => $enrollment->id, 'status' => 'present']]])->assertOk();
        $this->actingAs($trainer, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/sessions/2/attendance', ['marks' => [['enrollment_id' => $enrollment->id, 'status' => 'absent']]])->assertOk();
        $lb = $this->actingAs($trainer, 'sanctum')->postJson('/api/v1/trainer/classes/CCNA-2611/logbooks', ['session_id' => $this->s1->id, 'date' => '2026-11-17', 'topic' => 'Dasar jaringan',
            'entries' => [['enrollment_id' => $enrollment->id, 'practice_status' => 'completed', 'observation' => 'Lancar']]])->assertCreated()->json('data.id');
        $criteria = collect($this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/assessments')->json('data.rubric.criteria'));
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/classes/CCNA-2611/assessments/{$enrollment->id}", ['scores' => $criteria->map(fn ($c) => ['criterion_id' => $c['id'], 'score' => 4])->all(), 'summary' => 'Sangat baik'])->assertOk();

        // trainer: tabel kelas + detail peserta (semua data, termasuk draf)
        $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer/classes/CCNA-2611/progress')->assertOk()
            ->assertJsonPath('data.participants.0.attendance_percent', 50)->assertJsonPath('data.participants.0.practice_completed', 1)->assertJsonPath('data.participants.0.notes_count', 1)
            ->assertJsonPath('data.participants.0.competency_percent', 100)->assertJsonPath('data.participants.0.competency_status', 'draft');
        $this->actingAs($trainer, 'sanctum')->getJson("/api/v1/trainer/classes/CCNA-2611/progress/{$enrollment->id}")->assertOk()
            ->assertJsonPath('data.attendance.present', 1)->assertJsonPath('data.attendance.held', 2)->assertJsonPath('data.practice.completed', 1)
            ->assertJsonPath('data.competency.percent', 100)->assertJsonCount(1, 'data.notes')->assertJsonPath('data.notes.0.observation', 'Lancar');
        $this->app['auth']->forgetGuards();

        // peserta: sebelum validasi → catatan & penilaian belum tampil
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/progress')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendance.percent', 50)->assertJsonPath('data.0.competency', null)->assertJsonCount(0, 'data.0.notes')->assertJsonPath('data.0.practice.logged', 0);
        $this->app['auth']->forgetGuards();

        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/logbooks/$lb/validate")->assertOk();
        $aid = CompetencyAssessment::query()->where('enrollment_id', $enrollment->id)->value('id');
        $this->actingAs($trainer, 'sanctum')->postJson("/api/v1/trainer/assessments/$aid/validate")->assertOk()->assertJsonPath('data.isCompetent', true);
        $this->app['auth']->forgetGuards();

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/progress')->assertOk()
            ->assertJsonPath('data.0.competency.status', 'validated')->assertJsonPath('data.0.competency.percent', 100)->assertJsonCount(6, 'data.0.competency.criteria')
            ->assertJsonCount(1, 'data.0.notes')->assertJsonPath('data.0.notes.0.practiceLabel', 'Praktik selesai')->assertJsonPath('data.0.practice.logged', 1);
    }

    public function test_admin_panel_monitoring_pages(): void
    {
        [, $enrollment] = $this->enrolledMember();
        $svc = app(MonitoringService::class);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $logbook = $svc->saveLogbook($this->schedule, null, ['date' => '2026-11-17', 'topic' => 'Sesi 1'], [['enrollment_id' => $enrollment->id, 'practice_status' => 'completed']], [], [], $admin);
        $a = $svc->assessmentFor($enrollment);
        $rubric = $a->rubric;
        foreach (['training-logbooks', 'training-logbooks/create', "training-logbooks/{$logbook->id}/edit", 'assessment-rubrics', 'assessment-rubrics/create', "assessment-rubrics/{$rubric->id}/edit", 'competency-assessments', "competency-assessments/{$a->id}/edit"] as $path) {
            $this->actingAs($admin, 'web')->get("/admin/$path")->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($student, 'web')->get('/admin/training-logbooks')->assertForbidden();
    }
}
