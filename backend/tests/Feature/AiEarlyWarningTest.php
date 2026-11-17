<?php

namespace Tests\Feature;

use App\Filament\Widgets\Executive\EarlyWarningTable;
use App\Models\AiInsight;
use App\Models\AiInsightFeedback;
use App\Models\AiModelVersion;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Trainer;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AiEarlyWarningService;
use App\Services\MemberService;
use App\Services\NotificationEvents;
use App\Services\OrganizationService;
use App\Support\Ai\EarlyWarning\BaselineRules;
use App\Support\Ai\EarlyWarning\FeatureExtractor;
use App\Support\Ai\EarlyWarning\RiskModel;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Early warning (statistik, tanpa LLM): mati bawaan, skor + alasan per peserta aktif, insight hanya untuk staf/trainer/
 * perusahaan sponsor, notifikasi tanpa duplikasi dengan aturan notifications:risk, evaluasi vs baseline, kalibrasi.
 */
class AiEarlyWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $trainerUser;

    private User $hr;

    private Organization $org;

    private TrainingPackage $package;

    private TrainingSchedule $active;

    private Enrollment $good;

    private Enrollment $bad;

    private Lesson $assignment;

    /** @var list<Lesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->seed(RolesSeeder::class);

        $orgs = app(OrganizationService::class);
        $this->org = $orgs->create(['legal_name' => 'PT Sponsor Uji']);
        $this->hr = User::factory()->create(['name' => 'Hana HR']);
        $orgs->addCorporateUser($this->org, $this->hr, 'hr_admin');

        $this->package = TrainingPackage::query()->create(['slug' => 'plc-dasar', 'title' => 'PLC Dasar', 'status' => 'published', 'duration_hours' => 16]);
        $course = Course::query()->create(['package_id' => $this->package->id, 'title' => 'PLC Online', 'slug' => 'plc-online', 'is_published' => true]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Modul 1']);
        foreach (['Dasar PLC', 'Ladder'] as $t) {
            $this->lessons[] = Lesson::query()->create(['module_id' => $module->id, 'title' => $t, 'type' => 'text', 'body' => '<p>materi</p>', 'is_published' => true]);
        }
        $this->assignment = Lesson::query()->create(['module_id' => $module->id, 'title' => 'Tugas 1', 'type' => 'assignment', 'is_published' => true,
            'settings' => ['due_at' => '2026-11-18 23:59:00', 'max_score' => 100]]);

        $this->trainerUser = User::factory()->create(['name' => 'Andi Trainer']);
        $trainer = Trainer::query()->create(['name' => 'Andi Trainer', 'slug' => 'andi-trainer', 'user_id' => $this->trainerUser->id, 'email' => 'andi@example.com', 'is_active' => true]);

        // Kelas aktif 16-25 Nov, 4 sesi sudah lewat.
        $this->active = $this->schedule('PLC-2611', '2026-11-16', '2026-11-25', ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-24']);
        $this->active->trainers()->attach($trainer->id, ['role' => 'lead']);
        $this->good = $this->enroll($this->active, 'Rajin', 'attending', ['present', 'present', 'present', 'present']);
        $this->learn($this->good, lessons: 2, submitAt: '2026-11-18 10:00:00', score: 90, lastSeen: '2026-11-19 20:00:00');
        $this->bad = $this->enroll($this->active, 'Tertinggal', 'attending', ['present', 'absent', 'absent', 'absent'], $this->org);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(string $code, string $start, string $end, array $sessions): TrainingSchedule
    {
        $s = TrainingSchedule::query()->create(['package_id' => $this->package->id, 'code' => $code, 'start_date' => $start, 'end_date' => $end, 'status' => 'scheduled', 'quota' => 30]);
        foreach ($sessions as $i => $date) {
            ClassSession::query()->create(['schedule_id' => $s->id, 'session_no' => $i + 1, 'date' => $date]);
        }

        return $s;
    }

    private function enroll(TrainingSchedule $s, string $name, string $status, array $att, ?Organization $sponsor = null): Enrollment
    {
        $u = User::factory()->create(['name' => $name, 'email' => strtolower($name).uniqid().'@example.com']);
        $m = app(MemberService::class)->createForUser($u);
        $e = Enrollment::query()->create([
            'schedule_id' => $s->id, 'member_id' => $m->id, 'participant_name' => $name, 'participant_email' => $u->email, 'status' => $status,
            'type' => $sponsor ? Enrollment::TYPE_CORPORATE : Enrollment::TYPE_SELF, 'sponsor_organization_id' => $sponsor?->id, 'enrolled_at' => $s->start_date,
            'completed_at' => in_array($status, ['completed', 'failed'], true) ? $s->end_date : null,
        ]);
        $sessions = $s->sessions()->orderBy('session_no')->get();
        foreach ($att as $i => $st) {
            Attendance::query()->create(['session_id' => $sessions[$i]->id, 'enrollment_id' => $e->id, 'status' => $st]);
        }

        return $e;
    }

    private function learn(Enrollment $e, int $lessons, ?string $submitAt, ?int $score, string $lastSeen): void
    {
        foreach (array_slice($this->lessons, 0, $lessons) as $l) {
            LessonProgress::query()->create(['enrollment_id' => $e->id, 'lesson_id' => $l->id, 'status' => 'completed', 'opened_at' => $lastSeen, 'completed_at' => $lastSeen, 'last_seen_at' => $lastSeen]);
        }
        if ($submitAt) {
            AssignmentSubmission::query()->create(['lesson_id' => $this->assignment->id, 'enrollment_id' => $e->id, 'text' => 'jawaban', 'status' => $score !== null ? 'graded' : 'submitted', 'submitted_at' => $submitAt, 'score' => $score]);
        }
    }

    /** Kelas selesai untuk evaluasi/kalibrasi: $good peserta lulus dengan kehadiran penuh, $bad tidak lulus dengan kehadiran rendah. */
    private function finishedClass(string $code, int $good, int $bad, int $badLuckyPass = 0): void
    {
        $s = $this->schedule($code, '2026-10-01', '2026-10-04', ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
        for ($i = 0; $i < $good; $i++) {
            $e = $this->enroll($s, "Lulus$code$i", 'completed', ['present', 'present', 'present', 'present']);
            $this->learn($e, 2, '2026-10-02 10:00:00', 85, '2026-10-04 10:00:00');
        }
        for ($i = 0; $i < $bad; $i++) {
            $e = $this->enroll($s, "Gagal$code$i", 'failed', ['present', 'absent', 'absent', 'absent']);
            $this->learn($e, 0, null, null, '2026-10-01 10:00:00');
        }
        for ($i = 0; $i < $badLuckyPass; $i++) {
            $this->enroll($s, "Mepet$code$i", 'completed', ['present', 'present', 'absent', 'absent']);
        }
    }

    private function enable(): void
    {
        config(['ai.enabled' => true, 'ai.use_cases.early_warning.enabled' => true]);
    }

    public function test_off_by_default_nothing_is_scored_or_shown(): void
    {
        $r = app(AiEarlyWarningService::class)->run();
        $this->assertSame('unavailable', $r['status']);
        $this->assertSame(0, AiInsight::query()->count());
        $this->artisan('ai:early-warning')->expectsOutputToContain('belum tersedia')->assertSuccessful();

        Sanctum::actingAs($this->trainerUser);
        $d = $this->getJson('/api/v1/trainer/classes/PLC-2611/progress')->assertOk()->json('data');
        $this->assertFalse($d['earlyWarning']['available']);
        $this->assertSame('Belum tersedia', $d['earlyWarning']['label']);
        $this->assertNull($d['participants'][0]['early_warning']);
    }

    public function test_scores_reasons_and_insights_for_active_participants(): void
    {
        $this->enable();
        $r = app(AiEarlyWarningService::class)->run();
        $this->assertSame(['ok', 2, 1], [$r['status'], $r['scored'], $r['flagged']]);
        $this->assertSame(RiskModel::PRIOR_VERSION, $r['model']);

        $bad = AiInsight::query()->where('subject_id', $this->bad->id)->sole();
        $good = AiInsight::query()->where('subject_id', $this->good->id)->sole();
        $this->assertSame(AiInsight::STATUS_APPROVED, $bad->status, 'early warning tampil langsung ke staf/trainer/sponsor');
        $this->assertGreaterThanOrEqual(70, $bad->score);
        $this->assertLessThan(20, $good->score);
        $this->assertSame('high', $bad->output['level']);
        $texts = array_column($bad->output['reasons'], 'text');
        $this->assertContains('Kehadiran 25% dari 4 sesi tercatat (syarat lulus 80%)', $texts);
        $this->assertContains('1 dari 1 tugas terlambat atau belum dikumpulkan', $texts);
        $this->assertLessThanOrEqual(3, count($texts));
        $this->assertSame($this->org->id, $bad->organization_id);
        $this->assertNull($good->organization_id);
        $this->assertSame(RiskModel::PRIOR_VERSION, $bad->model_version);
        $this->assertNotNull($bad->expires_at);
        $this->assertStringNotContainsString('Tertinggal', json_encode($bad->output).json_encode($bad->data_reference), 'insight tidak menyimpan nama peserta');

        // Hari berikutnya: skor baru menggantikan yang lama (satu yang tampil per peserta).
        Carbon::setTestNow('2026-11-21 07:30:00');
        app(AiEarlyWarningService::class)->run();
        $this->assertSame(1, AiInsight::query()->visible()->where('subject_id', $this->bad->id)->count());
        $this->assertSame(1, AiInsight::query()->where('subject_id', $this->bad->id)->where('status', AiInsight::STATUS_EXPIRED)->count());
    }

    public function test_notifications_reach_trainer_and_sponsor_without_duplicating_rule_alerts(): void
    {
        $this->enable();
        app(AiEarlyWarningService::class)->run();

        $byAudience = fn () => NotificationDelivery::query()->where('channel', 'database')->get()->groupBy('audience')->map->count()->all();
        $this->assertSame(['corporate' => 1, 'trainer' => 1], collect($byAudience())->sortKeys()->all());
        $trainerMsg = NotificationDelivery::query()->where('audience', 'trainer')->where('channel', 'database')->sole();
        $this->assertStringContainsString('Tertinggal', $trainerMsg->content['title']);
        $this->assertStringContainsString('dibuat AI', $trainerMsg->content['body']);
        $this->assertSame(0, NotificationDelivery::query()->where('audience', 'participant')->count(), 'peserta tidak menerima skor AI');

        // Aturan lama berjalan setelahnya: peserta mendapat peringatan aturan, perusahaan tidak menerima peringatan kedua.
        app(NotificationEvents::class)->progressRisk();
        $after = $byAudience();
        $this->assertSame(1, $after['corporate']);
        $this->assertSame(1, $after['trainer']);
        $this->assertGreaterThanOrEqual(1, $after['participant'] ?? 0);

        // Menjalankan ulang di hari yang sama tidak mengirim ulang.
        app(AiEarlyWarningService::class)->run();
        $this->assertSame($after, $byAudience());
    }

    public function test_visibility_trainer_sponsor_and_feedback_access(): void
    {
        $this->enable();
        app(AiEarlyWarningService::class)->run();
        $badInsight = AiInsight::query()->where('subject_id', $this->bad->id)->sole();
        $goodInsight = AiInsight::query()->where('subject_id', $this->good->id)->sole();

        Sanctum::actingAs($this->trainerUser);
        $d = $this->getJson('/api/v1/trainer/classes/PLC-2611/progress')->assertOk()->json('data');
        $this->assertTrue($d['earlyWarning']['available']);
        $rows = collect($d['participants'])->keyBy('enrollment_id');
        $this->assertSame('high', $rows[$this->bad->id]['early_warning']['level']);
        $this->assertTrue($rows[$this->bad->id]['early_warning']['aiGenerated']);
        $this->assertSame('low', $rows[$this->good->id]['early_warning']['level']);
        $this->getJson("/api/v1/trainer/classes/PLC-2611/progress/{$this->bad->id}")->assertOk()->assertJsonPath('data.earlyWarning.insightId', $badInsight->id);
        $this->postJson("/api/v1/ai-insights/{$badInsight->id}/feedback", ['helpful' => true])->assertOk();
        $this->postJson("/api/v1/ai-insights/{$badInsight->id}/feedback", ['helpful' => false, 'note' => 'Sudah ditangani'])->assertOk();
        $this->assertSame(1, AiInsightFeedback::query()->count());
        $this->getJson('/api/v1/trainer/classes/PLC-2611/progress')->assertJsonPath('data.participants.1.early_warning.myFeedback', false);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/corporate/organizations/{$this->org->code}/enrollments/{$this->bad->id}")->assertOk()
            ->assertJsonPath('data.earlyWarning.score', (int) $badInsight->score);
        $this->postJson("/api/v1/ai-insights/{$badInsight->id}/feedback", ['helpful' => true])->assertOk();
        // Peserta mandiri (bukan sponsor) tidak bisa dijangkau perusahaan.
        $this->postJson("/api/v1/ai-insights/{$goodInsight->id}/feedback", ['helpful' => true])->assertNotFound();

        // Peserta sendiri tidak melihat skor dan tidak bisa memberi umpan balik.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Member::query()->find($this->bad->member_id)->user);
        $this->postJson("/api/v1/ai-insights/{$badInsight->id}/feedback", ['helpful' => true])->assertNotFound();
        $this->getJson('/api/v1/trainer/classes/PLC-2611/progress')->assertForbidden();
    }

    public function test_evaluation_and_calibration_against_rule_baseline(): void
    {
        Storage::fake('local');
        $this->finishedClass('OKT-A', good: 8, bad: 3, badLuckyPass: 1);
        $this->finishedClass('OKT-B', good: 6, bad: 2);

        $samples = app(FeatureExtractor::class)->finished();
        $this->assertCount(20, $samples);
        $failed = $samples->firstWhere('outcome_failed', true);
        $this->assertSame(25, $failed['raw']['attendance']);
        $this->assertTrue(BaselineRules::flagged($failed['raw'], true));

        $eval = app(AiEarlyWarningService::class)->evaluate();
        $this->assertSame([20, 5], [$eval['n'], $eval['positives']]);
        $this->assertSame(1.0, $eval['model']['recall']);
        $this->assertNotNull($eval['baseline']['f1']);

        $this->artisan('ai:early-warning-calibrate')->expectsOutputToContain('Data belum cukup')->assertSuccessful();
        $this->assertSame(0, AiModelVersion::query()->count());

        $this->artisan('ai:early-warning-calibrate', ['--force' => true])->expectsOutputToContain('hanya membuktikan pipeline')->assertSuccessful();
        $v = AiModelVersion::query()->sole();
        $this->assertSame('early_warning', $v->use_case);
        $this->assertArrayHasKey('holdout', $v->metrics);
        $this->assertSame(14, $v->training_size);
        if ($v->is_active) {
            $this->assertSame($v->version, app(AiEarlyWarningService::class)->model()->version);
        }

        $this->artisan('ai:evaluate', ['usecase' => 'early_warning'])
            ->expectsOutputToContain('baseline_f1')
            ->expectsOutputToContain('Data belum cukup')
            ->assertSuccessful();
    }

    public function test_attendance_counts_only_recorded_sessions_and_needs_evidence(): void
    {
        // Sesi hari ini belum diabsen: tidak dihitung sebagai "tidak hadir".
        ClassSession::query()->create(['schedule_id' => $this->active->id, 'session_no' => 6, 'date' => '2026-11-20']);
        $rows = app(FeatureExtractor::class)->active()->keyBy('enrollment_id');
        $this->assertSame([100, 4], [$rows[$this->good->id]['raw']['attendance'], $rows[$this->good->id]['raw']['attendance_sessions']]);
        $this->assertSame(0.75, $rows[$this->bad->id]['x']['attendance']);

        // Kelas baru dengan satu sesi tercatat: defisit kehadiran diredam (1 dari 3 sesi bukti).
        $s = $this->schedule('PLC-2611B', '2026-11-20', '2026-11-28', ['2026-11-20', '2026-11-21']);
        $e = $this->enroll($s, 'Baru', 'enrolled', ['absent']);
        $x = app(FeatureExtractor::class)->active()->firstWhere('enrollment_id', $e->id)['x'];
        $this->assertEqualsWithDelta(1 / 3, $x['attendance'], 0.001);
        $this->assertLessThan(50, RiskModel::prior()->predict(['attendance' => $x['attendance']])['score'], 'satu sesi absen saja belum cukup untuk dianggap berisiko');
        $this->assertGreaterThanOrEqual(50, RiskModel::prior()->predict(['attendance' => 1.0])['score'], 'tanpa peredam, sesi yang sama langsung berisiko');

        // Belum ada sesi yang diabsen sama sekali: kehadiran tidak diukur.
        $s2 = $this->schedule('PLC-2611C', '2026-11-20', '2026-11-28', ['2026-11-20']);
        $e2 = $this->enroll($s2, 'Kosong', 'enrolled', []);
        $this->assertNull(app(FeatureExtractor::class)->active()->firstWhere('enrollment_id', $e2->id)['x']['attendance']);
    }

    public function test_without_failed_participants_the_comparison_is_not_judged(): void
    {
        Storage::fake('local');
        $this->finishedClass('OKT-A', good: 5, bad: 0);
        $eval = app(AiEarlyWarningService::class)->evaluate();
        $this->assertSame([5, 0], [$eval['n'], $eval['positives']]);
        $this->assertNull($eval['beats_baseline'], 'tanpa peserta tidak lulus, model tidak bisa dinyatakan kalah atau menang');
        $this->artisan('ai:evaluate', ['usecase' => 'early_warning'])->expectsOutputToContain('belum dapat dinilai')->assertSuccessful();
    }

    public function test_fit_moves_weights_toward_the_data(): void
    {
        $samples = [];
        for ($i = 0; $i < 40; $i++) {
            $samples[] = ['x' => ['attendance' => 0.0, 'practice' => 0.8], 'y' => false];
            $samples[] = ['x' => ['attendance' => 0.7, 'practice' => 0.0], 'y' => true];
        }
        $m = RiskModel::fit($samples, 'uji');
        $this->assertGreaterThan(RiskModel::PRIOR['weights']['attendance'], $m->weights['attendance']);
        $this->assertLessThan(RiskModel::PRIOR['weights']['practice'], $m->weights['practice']);
        $this->assertGreaterThan(50, $m->predict(['attendance' => 0.7])['score']);
        $this->assertLessThan(50, $m->predict(['practice' => 0.8])['score']);
        $this->assertSame($m->params(), RiskModel::fit($samples, 'uji')->params(), 'deterministik');
    }

    public function test_early_warning_never_writes_official_tables(): void
    {
        $this->enable();
        $this->finishedClass('OKT-A', good: 8, bad: 3);
        $official = ['enrollments', 'registrations', 'attendances', 'certificates', 'competency_assessments', 'quiz_attempts',
            'assignment_submissions', 'lesson_progress', 'invoices', 'payment_transactions', 'members', 'training_logbooks', 'logbook_entries'];
        $writes = [];
        DB::listen(function ($q) use (&$writes, $official): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*?\b(into|from|update)?\s*"?('.implode('|', $official).')"?\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        app(AiEarlyWarningService::class)->run();
        app(AiEarlyWarningService::class)->calibrate(force: true);
        app(AiEarlyWarningService::class)->evaluate();
        $this->assertSame([], $writes);
    }

    public function test_executive_widget_shows_flagged_participants(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EarlyWarningTable::class)->assertOk()->assertSee('Belum tersedia');

        $this->enable();
        app(AiEarlyWarningService::class)->run();
        $insight = AiInsight::query()->where('subject_id', $this->bad->id)->sole();
        Livewire::test(EarlyWarningTable::class)->assertOk()
            ->assertSee('Peringatan dini (dibuat AI)')
            ->assertSee('Tertinggal')
            ->assertSee('Kehadiran 25%')
            ->assertDontSee('Rajin')
            ->callTableAction('helpful', $insight->id);
        $this->assertTrue(AiInsightFeedback::query()->where('user_id', $this->admin->id)->sole()->helpful);
        $this->get('/admin/dashboard-eksekutif')->assertOk();
    }
}
