<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\SurveyResponse;
use App\Models\SurveyTemplate;
use App\Models\Trainer;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\KpiService;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Laporan portal trainer: hanya kelas sendiri, angka sesuai data (rumus KPI yang sama), ekspor per kelas. */
class TrainerReportTest extends TestCase
{
    use RefreshDatabase;

    private TrainingSchedule $mine;

    private TrainingSchedule $older;

    private TrainingSchedule $other;

    private User $trainerUser;

    private User $otherTrainerUser;

    /** @var array<string, Enrollment> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);

        $plc = TrainingPackage::query()->create(['slug' => 'plc', 'title' => 'PLC Dasar', 'status' => 'published']);
        $this->mine = TrainingSchedule::query()->create(['package_id' => $plc->id, 'code' => 'PLC-2611', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'status' => 'completed', 'quota' => 20]);
        $this->older = TrainingSchedule::query()->create(['package_id' => $plc->id, 'code' => 'PLC-2609', 'start_date' => '2026-09-01', 'end_date' => '2026-09-03', 'status' => 'completed', 'quota' => 20]);
        $this->other = TrainingSchedule::query()->create(['package_id' => $plc->id, 'code' => 'PLC-LAIN', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'status' => 'completed', 'quota' => 20]);

        $this->trainerUser = User::factory()->create(['role' => User::ROLE_TRAINER]);
        $t = Trainer::query()->create(['name' => 'Andi', 'slug' => 'andi', 'user_id' => $this->trainerUser->id]);
        $this->mine->trainers()->attach($t->id, ['role' => 'lead']);
        $this->older->trainers()->attach($t->id, ['role' => 'lead']);
        $this->otherTrainerUser = User::factory()->create(['role' => User::ROLE_TRAINER]);
        $t2 = Trainer::query()->create(['name' => 'Budi', 'slug' => 'budi', 'user_id' => $this->otherTrainerUser->id]);
        $this->other->trainers()->attach($t2->id, ['role' => 'lead']);

        // Kelas saya: 4 sesi, 5 peserta (lulus 2, tidak lulus 1, belum dievaluasi 1, mundur 1)
        $sessions = collect(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-04'])
            ->map(fn ($d, $i) => ClassSession::query()->create(['schedule_id' => $this->mine->id, 'session_no' => $i + 1, 'date' => $d]));
        foreach ([['ana', 'completed', 90, 4], ['bayu', 'completed', 80, 3], ['citra', 'failed', 50, 1], ['dodi', 'attending', null, 2], ['eka', 'dropped', null, 0]] as [$name, $status, $score, $present]) {
            $this->e[$name] = Enrollment::query()->create([
                'schedule_id' => $this->mine->id, 'participant_name' => ucfirst($name), 'participant_email' => "$name@example.com", 'status' => $status,
                'final_score' => $score, 'enrolled_at' => '2026-11-01', 'completed_at' => in_array($status, ['completed', 'failed'], true) ? '2026-11-04' : null,
            ]);
            foreach ($sessions->take($present) as $s) {
                Attendance::query()->create(['session_id' => $s->id, 'enrollment_id' => $this->e[$name]->id, 'status' => 'present']);
            }
        }
        Enrollment::query()->create(['schedule_id' => $this->other->id, 'participant_name' => 'Rahasia', 'participant_email' => 'rahasia@example.com', 'status' => 'completed', 'final_score' => 99, 'completed_at' => '2026-11-04']);

        // LMS: 2 lesson wajib; Ana selesai 2, Bayu 1, lainnya 0
        $course = Course::query()->create(['package_id' => $plc->id, 'title' => 'PLC Online', 'slug' => 'plc-online', 'is_published' => true]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Modul 1', 'is_published' => true]);
        $l1 = Lesson::query()->create(['module_id' => $module->id, 'title' => 'Pengantar', 'type' => 'text', 'is_published' => true, 'is_required' => true]);
        $l2 = Lesson::query()->create(['module_id' => $module->id, 'title' => 'Ladder', 'type' => 'text', 'is_published' => true, 'is_required' => true]);
        foreach ([[$this->e['ana'], $l1], [$this->e['ana'], $l2], [$this->e['bayu'], $l1]] as [$en, $lesson]) {
            LessonProgress::query()->create(['enrollment_id' => $en->id, 'lesson_id' => $lesson->id, 'status' => 'completed']);
        }

        // Survei: 3 pengisi (rata-rata tampil) di kelas saya; kelas lama 2 pengisi (disembunyikan)
        $tpl = SurveyTemplate::default();
        $answers = fn (int $v) => ['overall' => $v, 'material' => $v, 'trainer' => $v, 'practice' => $v, 'facility' => $v];
        foreach ([['ana', 5], ['bayu', 4], ['citra', 3]] as [$name, $v]) {
            SurveyResponse::query()->create(['template_id' => $tpl->id, 'schedule_id' => $this->mine->id, 'enrollment_id' => $this->e[$name]->id, 'status' => 'submitted', 'answers' => $answers($v), 'submitted_at' => now()]);
        }
        SurveyResponse::query()->create(['template_id' => $tpl->id, 'schedule_id' => $this->mine->id, 'enrollment_id' => $this->e['dodi']->id, 'status' => 'pending']);
        foreach ([1, 2] as $i) {
            $en = Enrollment::query()->create(['schedule_id' => $this->older->id, 'participant_name' => "Lama $i", 'participant_email' => "lama$i@example.com", 'status' => 'completed', 'completed_at' => '2026-09-03']);
            SurveyResponse::query()->create(['template_id' => $tpl->id, 'schedule_id' => $this->older->id, 'enrollment_id' => $en->id, 'status' => 'submitted', 'answers' => $answers(2), 'submitted_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_lists_only_assigned_classes_with_numbers_from_data(): void
    {
        $res = $this->actingAs($this->trainerUser, 'sanctum')->getJson('/api/v1/trainer/reports')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'PLC-2611')
            ->assertJsonPath('data.1.code', 'PLC-2609');
        $this->assertNotContains('PLC-LAIN', array_column($res->json('data'), 'code'));

        $c = $res->json('data.0');
        $this->assertSame(4, $c['participants']);
        $this->assertSame(1, $c['dropped']);
        $this->assertSame(2, $c['passed']);
        $this->assertSame(1, $c['failed']);
        $this->assertSame(1, $c['notEvaluated']);
        // Kehadiran: (4 + 3 + 1 + 2) hadir dari 4 sesi x 4 peserta aktif = 62,5%
        $this->assertEquals(62.5, $c['attendanceRate']);
        $kpi = app(KpiService::class)->attendanceRate(['from' => '2026-11-02', 'to' => '2026-11-04', 'schedule_ids' => [$this->mine->id]]);
        $this->assertEquals($kpi['value'], $c['attendanceRate']);
        // Completion Rate KPI: 2 lulus dari 4 eligible
        $this->assertEquals(50.0, $c['completionRate']);
        $this->assertEquals(73.3, $c['averageFinalScore']); // (90 + 80 + 50) / 3
        $this->assertSame(38, $c['lmsAverage']); // (100 + 50 + 0 + 0) / 4 = 37,5 dibulatkan
        $this->assertEquals(['invited' => 4, 'submitted' => 3, 'average' => 4.0, 'hidden' => false, 'minResponses' => 3], $c['survey']);

        // Kelas lama: 2 pengisi < 3, skor survei disembunyikan; tanpa sesi -> kehadiran null
        $old = $res->json('data.1');
        $this->assertTrue($old['survey']['hidden']);
        $this->assertNull($old['survey']['average']);
        $this->assertSame(2, $old['survey']['submitted']);
        $this->assertNull($old['attendanceRate']);
        $this->assertNull($old['averageFinalScore']);
    }

    public function test_completion_rate_waits_until_class_ends(): void
    {
        $running = TrainingSchedule::query()->create(['package_id' => $this->mine->package_id, 'code' => 'PLC-JALAN', 'start_date' => '2026-11-18', 'end_date' => '2026-11-25', 'status' => 'ongoing', 'quota' => 20]);
        $running->trainers()->attach(Trainer::query()->where('user_id', $this->trainerUser->id)->value('id'), ['role' => 'lead']);
        Enrollment::query()->create(['schedule_id' => $running->id, 'participant_name' => 'Cepat', 'participant_email' => 'cepat@example.com', 'status' => 'completed', 'completed_at' => '2026-11-19']);
        Enrollment::query()->create(['schedule_id' => $running->id, 'participant_name' => 'Jalan', 'participant_email' => 'jalan@example.com', 'status' => 'attending']);

        $c = collect($this->actingAs($this->trainerUser, 'sanctum')->getJson('/api/v1/trainer/reports?status=ongoing')->assertOk()->json('data'))->firstWhere('code', 'PLC-JALAN');
        $this->assertSame(1, $c['passed']);
        $this->assertSame(1, $c['notEvaluated']);
        $this->assertNull($c['completionRate']);
    }

    public function test_filters_by_period_and_status(): void
    {
        $this->actingAs($this->trainerUser, 'sanctum');
        $this->getJson('/api/v1/trainer/reports?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PLC-2611');
        $this->getJson('/api/v1/trainer/reports?to=2026-10-01')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PLC-2609');
        $this->getJson('/api/v1/trainer/reports?status=ongoing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/trainer/reports?status=bukan-status')->assertStatus(422);
        $this->getJson('/api/v1/trainer/reports?from=2026-11-30&to=2026-11-01')->assertStatus(422);
    }

    public function test_other_trainers_class_and_non_trainers_are_rejected(): void
    {
        $this->actingAs($this->trainerUser, 'sanctum');
        $this->get('/api/v1/trainer/reports/PLC-LAIN/export.csv')->assertForbidden();
        $this->get('/api/v1/trainer/reports/TIDAK-ADA/export.csv')->assertNotFound();
        $this->get('/api/v1/trainer/reports/PLC-2611/export.pdf')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->otherTrainerUser, 'sanctum')->getJson('/api/v1/trainer/reports')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PLC-LAIN');
        $this->get('/api/v1/trainer/reports/PLC-2611/export.xlsx')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/trainer/reports')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/trainer/reports')->assertUnauthorized();
    }

    public function test_export_per_class_csv_and_xlsx(): void
    {
        $this->actingAs($this->trainerUser, 'sanctum');
        $res = $this->get('/api/v1/trainer/reports/PLC-2611/export.csv')->assertOk();
        $this->assertStringContainsString('attachment; filename=laporan-kelas-PLC-2611-', $res->headers->get('content-disposition'));
        $csv = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(6, $lines); // header + 5 enrollment (termasuk yang mundur, ditandai statusnya)
        $this->assertStringContainsString('Nama,Email,Status,"Kehadiran %","Progres LMS %"', $lines[0]);
        $this->assertStringContainsString('Ana,ana@example.com,Lulus,100,100,2/2', $csv);
        $this->assertStringContainsString('Eka,eka@example.com,Mundur', $csv);
        $this->assertStringNotContainsString('Rahasia', $csv);

        $xlsx = $this->get('/api/v1/trainer/reports/PLC-2611/export.xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
        $this->assertSame(2, AuditLog::query()->where('action', 'trainer.report_exported')->count());
    }
}
