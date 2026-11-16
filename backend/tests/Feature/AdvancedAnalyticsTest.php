<?php

namespace Tests\Feature;

use App\Filament\Pages\ExecutiveDashboard;
use App\Filament\Resources\Competencies\Pages\CreateCompetency;
use App\Filament\Resources\Competencies\Pages\EditCompetency;
use App\Filament\Resources\Competencies\Pages\ListCompetencies;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\RelationManagers\CompetencyTargetsRelationManager;
use App\Filament\Widgets\Executive\CohortTable;
use App\Filament\Widgets\Executive\CorporateFunnelChart;
use App\Filament\Widgets\Executive\KpiTable;
use App\Filament\Widgets\Executive\LearningStats;
use App\Filament\Widgets\Executive\SegmentStats;
use App\Models\ApiClient;
use App\Models\AssessmentRubric;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Competency;
use App\Models\CompetencyAssessment;
use App\Models\CompetencyTarget;
use App\Models\CorporateUser;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Registration;
use App\Models\RequestSeat;
use App\Models\TrainingPackage;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CompetencyGapService;
use App\Services\EmploymentService;
use App\Services\KpiService;
use App\Services\MemberService;
use App\Services\OrganizationService;
use App\Services\SalesAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\CmsSeeder;
use Database\Seeders\CompetencySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5F — KPI §8.1 persis docs/KPI.md (angka dihitung manual di komentar), corporate vs self-funded,
 * funnel & cohort, analitik perusahaan §20.15 dengan batas scope/peran, master kompetensi + target per posisi (gap §20.16).
 */
class AdvancedAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-11-30'];

    private OrganizationService $orgs;

    private Organization $abc;

    private Organization $xyz;

    private OrganizationUnit $eng;

    private OrganizationUnit $prod;

    private TrainingPackage $p1;

    private TrainingPackage $p2;

    private TrainingSchedule $s1;

    private TrainingSchedule $s2;

    private User $admin;

    private User $owner;

    private User $hr;

    /** @var array<string, Enrollment> */
    private array $e = [];

    /** @var array<string, Member> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed(RolesSeeder::class);
        Notification::fake();
        $this->orgs = app(OrganizationService::class);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $name): Member
    {
        $u = User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'email_verified_at' => now()]);

        return $this->m[$name] = app(MemberService::class)->createForUser($u)->load('user');
    }

    private function schedule(string $code, TrainingPackage $p, string $start, string $end, string $status, array $sessions, ?array $times = null): TrainingSchedule
    {
        $s = TrainingSchedule::query()->create(['package_id' => $p->id, 'code' => $code, 'start_date' => $start, 'end_date' => $end, 'status' => $status, 'quota' => 20]);
        foreach ($sessions as $i => $date) {
            ClassSession::query()->create(['schedule_id' => $s->id, 'session_no' => $i + 1, 'date' => $date, 'start_time' => $times[0] ?? null, 'end_time' => $times[1] ?? null]);
        }

        return $s;
    }

    /** @param  array<int, string>  $att  status per sesi (urut) */
    private function enroll(string $key, TrainingSchedule $s, string $name, string $status, ?Organization $sponsor = null, ?OrganizationUnit $unit = null, array $att = []): Enrollment
    {
        $member = $this->m[$name] ?? $this->member($name);
        $e = Enrollment::query()->create([
            'schedule_id' => $s->id, 'member_id' => $member->id, 'participant_name' => $name, 'participant_email' => $member->user->email, 'status' => $status,
            'type' => $sponsor ? Enrollment::TYPE_CORPORATE : Enrollment::TYPE_SELF, 'sponsor_organization_id' => $sponsor?->id, 'sponsor_unit_id' => $unit?->id,
            'enrolled_at' => $s->start_date, 'completed_at' => in_array($status, ['completed', 'failed'], true) ? $s->end_date : null,
        ]);
        $sessions = $s->sessions()->orderBy('session_no')->get();
        foreach ($att as $i => $st) {
            Attendance::query()->create(['session_id' => $sessions[$i]->id, 'enrollment_id' => $e->id, 'status' => $st]);
        }

        return $this->e[$key] = $e;
    }

    private function request(string $code, Organization $org, int $seats, ?OrganizationUnit $unit, array $at, array $seatEnrollments = []): TrainingRequest
    {
        $r = TrainingRequest::query()->create([
            'code' => $code, 'organization_id' => $org->id, 'package_id' => $this->p1->id, 'unit_id' => $unit?->id, 'seats' => $seats, 'delivery' => 'onsite',
            'status' => $at['status'] ?? 'paid', 'submitted_at' => $at['submitted'] ?? null, 'quoted_at' => $at['quoted'] ?? null,
            'approved_at' => $at['approved'] ?? null, 'paid_at' => $at['paid'] ?? null,
        ]);
        $r->forceFill(['created_at' => $at['created'] ?? $at['submitted'] ?? now()])->save();
        for ($i = 1; $i <= $seats; $i++) {
            $e = $seatEnrollments[$i - 1] ?? null;
            RequestSeat::query()->create(['training_request_id' => $r->id, 'seat_no' => $i, 'status' => $e ? 'enrolled' : 'reserved', 'enrollment_id' => $e?->id, 'member_id' => $e?->member_id, 'unit_id' => $unit?->id]);
        }

        return $r;
    }

    private function invoice(array $attrs, float $paid, ?string $paidAt, float $refund = 0): Invoice
    {
        static $n = 0;
        $n++;
        $inv = Invoice::query()->create($attrs + [
            'invoice_number' => sprintf('INV-TEST-%04d', $n), 'bill_to_name' => 'Tagihan '.$n, 'subtotal' => $attrs['total'], 'amount_paid' => $paid,
            'amount_refunded' => $refund, 'paid_at' => $paidAt, 'status' => $paidAt ? ($refund >= $paid && $paid > 0 ? 'refunded' : 'paid') : 'pending',
        ]);
        if ($paid > 0) {
            PaymentTransaction::query()->create(['code' => sprintf('TRX-TEST-%05d', $n), 'invoice_id' => $inv->id, 'amount' => $paid, 'amount_received' => $paid, 'status' => 'paid', 'verified_at' => $paidAt]);
        }
        if ($refund > 0) {
            PaymentRefund::query()->create(['code' => sprintf('RFD-TEST-%04d', $n), 'invoice_id' => $inv->id, 'amount' => $refund, 'reason' => 'Uji', 'status' => 'completed', 'completed_at' => $paidAt]);
        }

        return $inv;
    }

    private function registration(string $name, string $status, string $createdAt, TrainingSchedule $s): Registration
    {
        $r = Registration::query()->create([
            'registration_number' => 'REG-TEST-'.strtoupper(substr($name, 0, 4)), 'schedule_id' => $s->id, 'package_id' => $s->package_id, 'type' => 'individual',
            'status' => $status, 'name' => $name, 'email' => strtolower($name).'@example.com', 'phone' => '0812', 'participants_count' => 1, 'consent' => true, 'total' => 3000000,
        ]);
        $r->forceFill(['created_at' => $createdAt])->save();

        return $r;
    }

    /**
     * Data uji (periode 1 Okt – 30 Nov 2026, hari ini 20 Nov):
     * S1 PLC-OKT (5–6 Okt, selesai, 2 sesi 08–16): Ani ABC/ENG lulus 2/2 · Budi ABC/PROD tidak lulus 1/2 · Citra mandiri lulus (hadir+terlambat)
     *   · Dodi mandiri attending 0/2 (izin) · Eko mandiri dropped · Fira XYZ lulus 2/2.
     * S3 dibatalkan: Hana mandiri "completed" (dikecualikan).
     * S2 PLC-NOV (18–25 Nov, berjalan, 4 sesi, tanpa jam → 16 JP ÷ 4 = 4 jam): Gita ABC/ENG attending 1/3 · Hadi mandiri 0/3 · Indra mandiri 3/3.
     * S4 (2025, mandiri) Budi lulus + penilaian PLC 4/4 — bukti mandiri, TIDAK boleh dipakai perusahaan.
     */
    private function fixture(): void
    {
        $this->p1 = TrainingPackage::query()->create(['slug' => 'plc-dasar', 'title' => 'PLC Dasar', 'status' => 'published', 'duration_hours' => 16]);
        $this->p2 = TrainingPackage::query()->create(['slug' => 'plc-lanjutan', 'title' => 'PLC Lanjutan', 'status' => 'published', 'duration_hours' => 24]);
        $this->abc = $this->orgs->create(['legal_name' => 'PT ABC Manufaktur']);
        $this->xyz = $this->orgs->create(['legal_name' => 'PT XYZ Energi']);
        $qrs = $this->orgs->create(['legal_name' => 'PT QRS Refund']);
        $this->eng = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Engineering', 'code' => 'ENG']);
        $this->prod = OrganizationUnit::query()->create(['organization_id' => $this->abc->id, 'name' => 'Produksi', 'code' => 'PRD']);

        $this->s1 = $this->schedule('PLC-OKT', $this->p1, '2026-10-05', '2026-10-06', 'completed', ['2026-10-05', '2026-10-06'], ['08:00:00', '16:00:00']);
        $this->s2 = $this->schedule('PLC-NOV', $this->p1, '2026-11-18', '2026-11-25', 'ongoing', ['2026-11-18', '2026-11-19', '2026-11-20', '2026-11-23']);
        $s3 = $this->schedule('PLC-BATAL', $this->p1, '2026-10-12', '2026-10-13', 'cancelled', ['2026-10-12']);
        $s4 = $this->schedule('PLC-2025', $this->p1, '2025-03-01', '2025-03-02', 'completed', []);

        $this->enroll('ani', $this->s1, 'Ani', 'completed', $this->abc, $this->eng, ['present', 'present']);
        $this->enroll('budi', $this->s1, 'Budi', 'failed', $this->abc, $this->prod, ['present', 'absent']);
        $this->enroll('citra', $this->s1, 'Citra', 'completed', null, null, ['present', 'late']);
        $this->enroll('dodi', $this->s1, 'Dodi', 'attending', null, null, ['excused', 'absent']);
        $this->enroll('eko', $this->s1, 'Eko', 'dropped', null, null, ['present']);
        $this->enroll('fira', $this->s1, 'Fira', 'completed', $this->xyz, null, ['present', 'present']);
        $this->enroll('hana', $s3, 'Hana', 'completed', null, null, ['present']);
        $this->enroll('gita', $this->s2, 'Gita', 'attending', $this->abc, $this->eng, ['present', 'absent', 'absent']);
        $this->enroll('hadi', $this->s2, 'Hadi', 'enrolled', null, null, ['absent', 'absent', 'absent']);
        $this->enroll('indra', $this->s2, 'Indra', 'attending', null, null, ['present', 'present', 'late']);
        $this->enroll('budi-self', $s4, 'Budi', 'completed');

        // Pendaftaran individu: Citra lunas, Dodi belum bayar, satu ditolak (bukan peluang terkualifikasi)
        $regCitra = $this->registration('Citra', 'confirmed', '2026-10-01 09:00:00', $this->s1);
        $regDodi = $this->registration('Dodi', 'waiting_payment', '2026-10-02 09:00:00', $this->s1);
        $this->registration('Spam', 'rejected', '2026-10-03 09:00:00', $this->s1);
        $this->e['citra']->update(['registration_id' => $regCitra->id]);
        $this->e['dodi']->update(['registration_id' => $regDodi->id]);

        // Corporate Training Request (satuan funnel) — R1 & R2 milik ABC (repeat), R3 XYZ, R4 draf ABC
        $r1 = $this->request('REQ-T-1', $this->abc, 2, $this->eng, ['status' => 'completed', 'submitted' => '2026-10-01', 'quoted' => '2026-10-01', 'approved' => '2026-10-01', 'paid' => '2026-10-01'], [$this->e['ani'], $this->e['budi']]);
        $r2 = $this->request('REQ-T-2', $this->abc, 1, $this->prod, ['status' => 'in_progress', 'submitted' => '2026-11-01', 'quoted' => '2026-11-02', 'approved' => '2026-11-03', 'paid' => '2026-11-10'], [$this->e['gita']]);
        $r3 = $this->request('REQ-T-3', $this->xyz, 2, null, ['status' => 'completed', 'submitted' => '2026-10-10', 'quoted' => '2026-10-11', 'approved' => '2026-10-12', 'paid' => '2026-10-15'], [$this->e['fira']]);
        $this->request('REQ-T-4', $this->abc, 3, null, ['status' => 'draft', 'created' => '2026-11-15']);

        // Order: ABC 10 jt + (6 jt − refund 1 jt), XYZ 4 jt, Citra 3 jt, Dodi belum lunas, QRS lunas lalu refund penuh (bukan order)
        $this->invoice(['training_request_id' => $r1->id, 'organization_id' => $this->abc->id, 'total' => 10000000], 10000000, '2026-10-01 10:00:00');
        $this->invoice(['training_request_id' => $r2->id, 'organization_id' => $this->abc->id, 'total' => 6000000], 6000000, '2026-11-10 10:00:00', 1000000);
        $this->invoice(['training_request_id' => $r3->id, 'organization_id' => $this->xyz->id, 'total' => 4000000], 4000000, '2026-10-15 10:00:00');
        $this->invoice(['registration_id' => $regCitra->id, 'total' => 3000000], 3000000, '2026-10-02 10:00:00');
        $this->invoice(['registration_id' => $regDodi->id, 'total' => 3000000], 0, null);
        $this->invoice(['organization_id' => $qrs->id, 'total' => 2000000], 2000000, '2026-10-20 10:00:00', 2000000);

        $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
        $this->orgs->addCorporateUser($this->abc, $this->owner, 'owner');
        $this->hr = User::factory()->create(['name' => 'Hana HR']);
        $this->orgs->addCorporateUser($this->abc, $this->hr, 'hr_admin');
        foreach (['Ani' => ['teknisi  maintenance', $this->eng], 'Budi' => ['Teknisi Maintenance', $this->prod], 'Gita' => ['Operator', $this->eng]] as $name => [$position, $unit]) {
            app(EmploymentService::class)->start($this->m[$name], $this->abc, ['position' => $position, 'unit_id' => $unit->id]);
        }
    }

    private function kpi(string $segment = 'all', ?CorporateUser $cu = null): array
    {
        return app(KpiService::class)->compute(self::PERIOD + ['segment' => $segment, 'corporate_user' => $cu]);
    }

    // ------------------------------------------------------------------ KPI §8.1

    public function test_completion_rate_follows_kpi_definition(): void
    {
        // eligible: kelas S1 sudah selesai, bukan dropped (Ani, Budi, Citra, Dodi, Fira); S2 belum berakhir; S3 dibatalkan
        $all = $this->kpi()['completion_rate'];
        $this->assertSame([3, 5, 60.0, 1, 1], [$all['numerator'], $all['denominator'], $all['value'], $all['failed'], $all['pending_evaluation']]);
        $this->assertSame(50.0, $this->kpi('self')['completion_rate']['value']);          // Citra / (Citra, Dodi)
        $this->assertSame(66.7, $this->kpi('corporate')['completion_rate']['value']);     // Ani, Fira / (Ani, Budi, Fira)
    }

    public function test_attendance_rate_pools_attended_over_scheduled_units(): void
    {
        // terjadwal: 5 enrollment × 2 sesi S1 + 3 enrollment × 3 sesi S2 yang sudah lewat = 19; hadir/terlambat = 2+1+2+0+2+1+0+3 = 11
        $all = $this->kpi()['attendance_rate'];
        $this->assertSame([11, 19, 57.9], [$all['numerator'], $all['denominator'], $all['value']]);
        $self = $this->kpi('self')['attendance_rate'];
        $this->assertSame([5, 10, 50.0], [$self['numerator'], $self['denominator'], $self['value']]);
        $this->assertSame(66.7, $this->kpi('corporate')['attendance_rate']['value']);   // 6 / 9
    }

    public function test_repeat_order_rate_and_average_order_value(): void
    {
        $k = $this->kpi();
        // ABC (2 order) & XYZ (1 order) eligible; QRS refund penuh bukan order
        $this->assertSame([1, 2, 50.0], [$k['repeat_order_rate']['numerator'], $k['repeat_order_rate']['denominator'], $k['repeat_order_rate']['value']]);
        $this->assertTrue($this->kpi('self')['repeat_order_rate']['not_applicable']);
        // (10 + 5 + 4 + 3) jt / 4 order
        $this->assertEquals(22000000, $k['average_order_value']['numerator']);
        $this->assertEquals(5500000, $k['average_order_value']['value']);
        $this->assertEquals(6333333.33, $this->kpi('corporate')['average_order_value']['value']);
        $this->assertEquals(3000000, $this->kpi('self')['average_order_value']['value']);
    }

    public function test_training_conversion_counts_paid_enrollments_over_qualified_opportunities(): void
    {
        // peluang: 2 pendaftaran (yang ditolak dikecualikan) + kursi R1 (2) + R2 (1) + R3 (2) = 7; R4 draf belum dikirim
        // enrollment lunas: Citra + Ani, Budi, Gita, Fira = 5
        $c = $this->kpi()['training_conversion'];
        $this->assertSame([5, 7, 71.4], [$c['numerator'], $c['denominator'], $c['value']]);
        $this->assertSame(50.0, $this->kpi('self')['training_conversion']['value']);
        $this->assertSame(80.0, $this->kpi('corporate')['training_conversion']['value']);
    }

    public function test_at_risk_rate_uses_dashboard_risk_rules(): void
    {
        // aktif: Dodi (S1, attending), Gita, Hadi, Indra; berisiko: Dodi 0%, Gita 33%, Hadi 0%
        $r = $this->kpi()['at_risk_rate'];
        $this->assertSame([3, 4, 75.0], [$r['numerator'], $r['denominator'], $r['value']]);
        $this->assertEqualsCanonicalizing(['Dodi', 'Gita', 'Hadi'], array_column($r['flagged'], 'name'));
        $this->assertSame(100.0, $this->kpi('corporate')['at_risk_rate']['value']);
    }

    public function test_kpi_doc_lists_every_kpi_definition(): void
    {
        $doc = (string) file_get_contents(base_path('../docs/KPI.md'));
        foreach (KpiService::KEYS as $k) {
            $this->assertStringContainsString('`'.$k.'`', $doc, "docs/KPI.md wajib mendefinisikan {$k}");
            $this->assertStringContainsString(KpiService::LABELS[$k], $doc);
        }
    }

    // ------------------------------------------------------------------ Dashboard Eksekutif

    public function test_executive_dashboard_segments_funnel_and_cohorts(): void
    {
        $d = app(SalesAnalyticsService::class)->dashboard(self::PERIOD);

        // enrollment bukan dropped di kelas tidak batal yang beririsan periode: mandiri Citra, Dodi, Hadi, Indra; corporate Ani, Budi, Fira, Gita
        $this->assertSame([4, 4, 2], [$d['segments']['self']['enrollments'], $d['segments']['corporate']['enrollments'], $d['segments']['corporate']['organizations']]);
        $this->assertEquals(19000000, $d['segments']['corporate']['order_value']);

        $f = collect($d['funnel']['stages'])->pluck('count', 'key')->all();
        $this->assertSame(['lead' => 4, 'request' => 3, 'quotation' => 3, 'approval' => 3, 'paid' => 3, 'completed' => 2, 'repeat' => 1], $f);
        $this->assertSame(75.0, collect($d['funnel']['stages'])->firstWhere('key', 'request')['from_previous']);

        $corp = collect($d['cohorts'])->firstWhere('key', 'corporate-2026-10');
        $this->assertSame([2, 1, 50.0, 40.0, 3], [$corp['customers'], $corp['repeat'], $corp['repeat_rate'], $corp['avg_days_to_repeat'], $corp['orders']]);
        $this->assertSame(1, collect($d['cohorts'])->firstWhere('key', 'self-2026-10')['customers']);

        // indikator completion pembelajaran memakai definisi KPI yang sama
        $this->assertSame(60.0, app(AnalyticsService::class)->dashboard(self::PERIOD)['learning']['completion_rate']);
    }

    public function test_executive_widgets_render_and_export_includes_kpi_rows(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/dashboard-eksekutif')->assertOk();
        foreach ([KpiTable::class, SegmentStats::class, CorporateFunnelChart::class, CohortTable::class, LearningStats::class] as $w) {
            Livewire::test($w, ['pageFilters' => self::PERIOD])->assertOk();
        }
        Livewire::test(KpiTable::class, ['pageFilters' => self::PERIOD])->assertSee('Repeat Order Rate')->assertSee('57,9%');
        Livewire::test(CohortTable::class, ['pageFilters' => self::PERIOD])->assertSee('Okt 26');
        Livewire::test(ExecutiveDashboard::class)->assertOk();

        $svc = app(AnalyticsService::class);
        $rows = collect($svc->flatten($svc->dashboard(self::PERIOD)));
        $this->assertSame(71.4, $rows->first(fn ($r) => $r[0] === 'KPI' && $r[1] === 'Training Conversion — Semua (%)')[2]);
        $this->assertSame(4, $rows->first(fn ($r) => $r[0] === 'Funnel corporate' && str_starts_with($r[1], 'Lead'))[2]);
    }

    // ------------------------------------------------------------------ Corporate analytics §20.15

    private function url(Organization $org, string $path): string
    {
        return "/api/v1/corporate/organizations/{$org->code}{$path}";
    }

    public function test_corporate_analytics_is_scoped_to_sponsored_data(): void
    {
        $res = $this->actingAs($this->owner, 'sanctum')->getJson($this->url($this->abc, '/analytics?from=2026-10-01&to=2026-11-30'))->assertOk();
        $d = $res->json('data');

        // hanya enrollment bersponsor ABC: Ani, Budi (S1), Gita (S2)
        $this->assertEquals(3, $d['enrollments']);
        $this->assertEquals(50, $d['kpi']['completion_rate']['value']);
        $this->assertEquals(57.1, $d['kpi']['attendance_rate']['value']);                 // 4 / 7
        $this->assertEquals(['requested' => 3, 'bought' => 3, 'assigned' => 3, 'enrolled' => 3, 'used' => 3, 'rate' => 100.0], $d['utilization']);
        $this->assertEquals(['PLC-NOV', 'PLC-OKT'], array_column($d['byBatch'], 'code'));
        $this->assertEquals(75.0, collect($d['byBatch'])->firstWhere('code', 'PLC-OKT')['attendanceRate']);   // 3 / 4
        // jam: Ani 2×8 + Budi 8 + Gita 1×4 (16 JP ÷ 4 sesi) = 28 jam / 3 karyawan
        $this->assertEquals(['total' => 28, 'perEmployee' => 9.3], $d['trainingHours']);
        $this->assertEquals(20, collect($d['byUnit'])->firstWhere('unit', 'ENG · Engineering')['hours']);
        $this->assertEquals(['PLC Dasar'], array_column($d['programs'], 'program'));
        $this->assertEquals(['orders' => 2, 'isRepeat' => true, 'programs' => 1, 'firstOrder' => '2026-10-01', 'lastOrder' => '2026-11-10', 'daysToRepeat' => 40], $d['repeat']);
        // biaya: (10 + 5) jt / 3 karyawan; per unit dari request
        $this->assertEquals(15000000, $d['cost']['total']);
        $this->assertEquals(5000000, $d['cost']['perEmployee']);
        $this->assertEquals(['ENG · Engineering', 'PRD · Produksi'], array_column($d['cost']['byUnit'], 'unit'));
        $this->assertEquals([1, 1], [$d['risk']['count'], $d['risk']['active']]);
        $this->assertEquals('Gita', $d['risk']['rows'][0]['name']);
        $this->assertArrayNotHasKey('email', $d['risk']['rows'][0]);
        $this->assertStringNotContainsString('Fira', $res->getContent());             // XYZ tidak pernah terlihat
        $this->assertStringNotContainsString('Citra', $res->getContent());            // self-funded tidak pernah terlihat
        $this->assertTrue(AuditLog::query()->where('action', 'corporate.analytics_viewed')->exists());
    }

    public function test_corporate_analytics_respects_roles_and_units(): void
    {
        // HR/L&D tanpa view_finance → tanpa biaya
        $this->actingAs($this->hr, 'sanctum')->getJson($this->url($this->abc, '/analytics'))->assertOk()->assertJsonPath('data.cost', null);
        $this->app['auth']->forgetGuards();

        // Finance → biaya ada, nama peserta berisiko tidak
        $fin = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $fin, 'finance');
        $this->actingAs($fin, 'sanctum')->getJson($this->url($this->abc, '/analytics?from=2026-10-01&to=2026-11-30'))->assertOk()
            ->assertJsonPath('data.risk.rows', null)->assertJsonPath('data.cost.total', 15000000);
        $this->app['auth']->forgetGuards();

        // Supervisor unit Produksi → hanya Budi
        $sup = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $sup, 'supervisor', $this->prod);
        $d = $this->actingAs($sup, 'sanctum')->getJson($this->url($this->abc, '/analytics?from=2026-10-01&to=2026-11-30'))->assertOk()->json('data');
        $this->assertEquals(1, $d['enrollments']);
        $this->assertEquals(0, $d['kpi']['completion_rate']['value']);
        $this->assertEquals([], $d['risk']['rows']);
        $this->app['auth']->forgetGuards();

        // organisasi lain → 404
        $this->actingAs($sup, 'sanctum')->getJson($this->url($this->xyz, '/analytics'))->assertNotFound();
    }

    public function test_resigned_participant_counts_in_aggregate_but_not_by_name(): void
    {
        $emp = $this->m['Gita']->employments()->where('organization_id', $this->abc->id)->firstOrFail();
        app(EmploymentService::class)->end($emp, '2026-11-19');
        $d = $this->actingAs($this->owner, 'sanctum')->getJson($this->url($this->abc, '/analytics?from=2026-10-01&to=2026-11-30'))->assertOk()->json('data');
        $this->assertEquals(1, $d['risk']['count']);
        $this->assertEquals([], $d['risk']['rows']);
    }

    // ------------------------------------------------------------------ Kompetensi §20.16

    private function competencyFixture(): Competency
    {
        $plc = Competency::query()->create(['code' => 'plc', 'name' => 'Pemrograman PLC']);
        $plc->packages()->attach($this->p1->id, ['level' => 60]);
        $plc->packages()->attach($this->p2->id, ['level' => 85]);
        $rubric = AssessmentRubric::query()->create(['package_id' => $this->p1->id, 'name' => 'Rubrik PLC']);
        $crit = $rubric->criteria()->create(['name' => 'Program ladder', 'competency_id' => $plc->id, 'weight' => 1, 'max_score' => 4]);
        $other = $rubric->criteria()->create(['name' => 'K3', 'weight' => 1, 'max_score' => 4]);
        $assess = function (Enrollment $e, float $score, string $status = 'validated') use ($rubric, $crit, $other): void {
            $a = CompetencyAssessment::query()->create(['enrollment_id' => $e->id, 'rubric_id' => $rubric->id, 'status' => $status, 'validated_at' => $status === 'validated' ? now() : null]);
            $a->scores()->create(['criterion_id' => $crit->id, 'score' => $score]);
            $a->scores()->create(['criterion_id' => $other->id, 'score' => 1]);
        };
        $assess($this->e['ani'], 3);                    // 75 → bukti assessment Ani
        $assess($this->e['budi-self'], 4);              // mandiri → diabaikan untuk perusahaan
        $assess($this->e['budi'], 4, 'draft');          // belum tervalidasi → diabaikan
        app(CompetencyGapService::class)->setTarget($this->abc, 'Teknisi Maintenance', $plc, 80, $this->hr);

        return $plc;
    }

    public function test_competency_gap_uses_only_sponsored_validated_evidence(): void
    {
        $this->competencyFixture();
        $m = app(CompetencyGapService::class)->forOrganization($this->abc);

        $this->assertSame(3, $m['employees']);
        $this->assertSame(2, $m['employees_with_targets']);                         // posisi dinormalisasi; Gita (Operator) tanpa target
        $rows = collect($m['rows'])->keyBy('name');
        $this->assertSame([80, 75, 'assessment', 5, false], [$rows['Ani']['required'], $rows['Ani']['actual'], $rows['Ani']['source'], $rows['Ani']['gap'], $rows['Ani']['met']]);
        $this->assertSame('plc-lanjutan', $rows['Ani']['recommendation']['slug']);
        $this->assertNull($rows['Budi']['actual']);                                  // penilaian mandiri 2025 & draf tidak dihitung
        $this->assertSame(80, $rows['Budi']['gap']);
        $this->assertSame(['employees' => 2, 'met' => 0, 'no_evidence' => 1, 'avg_gap' => 42.5], array_intersect_key($m['summary'][0], array_flip(['employees', 'met', 'no_evidence', 'avg_gap'])));

        // tanpa penilaian: program lulus memberi level 60 (bukti training)
        CompetencyAssessment::query()->where('enrollment_id', $this->e['ani']->id)->delete();
        $ani = collect(app(CompetencyGapService::class)->forOrganization($this->abc)['rows'])->firstWhere('name', 'Ani');
        $this->assertSame([60, 'training', 20], [$ani['actual'], $ani['source'], $ani['gap']]);
    }

    public function test_corporate_competency_endpoints_and_permissions(): void
    {
        $this->competencyFixture();
        Competency::query()->create(['code' => 'HMI', 'name' => 'Desain HMI']);

        $d = $this->actingAs($this->hr, 'sanctum')->getJson($this->url($this->abc, '/competencies'))->assertOk()->json('data');
        $this->assertTrue($d['canManage']);
        $this->assertSame(['HMI', 'PLC'], collect($d['catalog'])->pluck('code')->sort()->values()->all());
        $this->assertSame(['Operator', 'Teknisi Maintenance', 'teknisi  maintenance'], $d['positions']);
        $this->assertCount(2, $d['rows']);

        // ubah target posisi yang sama (beda huruf besar/kecil) → diperbarui, bukan duplikat; diaudit
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'teknisi maintenance', 'competency' => 'plc', 'required_level' => 70])->assertOk();
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'Operator', 'competency' => 'HMI', 'required_level' => 60])->assertCreated();
        $this->assertSame(2, CompetencyTarget::query()->where('organization_id', $this->abc->id)->count());
        $this->assertSame(70, CompetencyTarget::query()->where('position_key', 'teknisi maintenance')->value('required_level'));
        $this->assertTrue(AuditLog::query()->where('action', 'competency_target.updated')->exists());
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'Operator', 'competency' => 'XXX', 'required_level' => 60])->assertUnprocessable();
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'Operator', 'competency' => 'HMI', 'required_level' => 120])->assertUnprocessable();

        // target organisasi lain tidak bisa dihapus lewat URL organisasi ini
        $foreign = app(CompetencyGapService::class)->setTarget($this->xyz, 'Operator', Competency::query()->where('code', 'HMI')->first(), 50, $this->admin);
        $this->deleteJson($this->url($this->abc, '/competency-targets/'.$foreign->id))->assertNotFound();
        $own = CompetencyTarget::query()->where('organization_id', $this->abc->id)->where('position', 'Operator')->firstOrFail();
        $this->deleteJson($this->url($this->abc, '/competency-targets/'.$own->id))->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'competency_target.deleted')->exists());
        $this->app['auth']->forgetGuards();

        // finance: ringkasan saja, tanpa nama; tidak bisa mengubah target
        $fin = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $fin, 'finance');
        $this->actingAs($fin, 'sanctum')->getJson($this->url($this->abc, '/competencies'))->assertOk()
            ->assertJsonPath('data.rows', null)->assertJsonPath('data.canManage', false)->assertJsonPath('data.positions', []);
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'Operator', 'competency' => 'HMI', 'required_level' => 60])->assertForbidden();
        $this->app['auth']->forgetGuards();

        // HR dengan batas unit tidak boleh mengubah target organisasi
        $hrEng = User::factory()->create();
        $this->orgs->addCorporateUser($this->abc, $hrEng, 'hr_admin', $this->eng);
        $this->actingAs($hrEng, 'sanctum')->getJson($this->url($this->abc, '/competencies'))->assertOk()->assertJsonPath('data.canManage', false)
            ->assertJsonCount(1, 'data.rows');                                        // hanya Ani (ENG) yang punya target
        $this->postJson($this->url($this->abc, '/competency-targets'), ['position' => 'Operator', 'competency' => 'HMI', 'required_level' => 60])->assertForbidden();
    }

    public function test_admin_competency_resources_render(): void
    {
        $plc = $this->competencyFixture();
        $this->actingAs($this->admin);
        $this->get('/admin/competencies')->assertOk()->assertSee('Pemrograman PLC');
        Livewire::test(ListCompetencies::class)->assertOk();
        Livewire::test(CreateCompetency::class)
            ->fillForm(['code' => 'scada', 'name' => 'Operasi SCADA'])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Competency::query()->where('code', 'SCADA')->exists());
        Livewire::test(EditCompetency::class, ['record' => $plc->getRouteKey()])->assertOk();
        Livewire::test(CompetencyTargetsRelationManager::class, ['ownerRecord' => $this->abc, 'pageClass' => EditOrganization::class])
            ->assertOk()->assertSee('Teknisi Maintenance');
    }

    public function test_competency_seeder_is_idempotent_and_links_catalog_programs(): void
    {
        $this->seed(CmsSeeder::class);
        $this->seed(CompetencySeeder::class);
        $this->seed(CompetencySeeder::class);
        $net = Competency::query()->where('code', 'NET')->firstOrFail();
        $this->assertSame(6, Competency::query()->count());
        $this->assertSame(['cisco-ccna' => 70, 'cisco-ccnp-enterprise' => 90, 'mikrotik-mtcna' => 65], $net->packages()->orderBy('slug')->get()->mapWithKeys(fn ($p) => [$p->slug => (int) $p->pivot->level])->all());
    }

    // ------------------------------------------------------------------ API enterprise

    public function test_enterprise_api_exposes_kpi_8_1_for_bound_organization(): void
    {
        $cu = CorporateUser::query()->where('user_id', $this->owner->id)->firstOrFail();
        $client = new ApiClient(['name' => 'HRIS ABC', 'organization_id' => $this->abc->id, 'corporate_user_id' => $cu->id, 'scopes' => ['analytics.read'], 'rate_limit' => 100]);
        $key = $client->generateKey();
        $client->save();

        $this->getJson('/api/public/v1/analytics?from=2026-10-01&to=2026-11-30', ['X-Api-Key' => $key])->assertOk()
            ->assertJsonPath('data.period.from', '2026-10-01')
            ->assertJsonPath('data.kpi_8_1.completion_rate.value', 50)
            ->assertJsonPath('data.kpi_8_1.repeat_order_rate.value', 100)
            ->assertJsonPath('data.kpi_8_1.training_conversion.denominator', 3)
            ->assertJsonMissingPath('data.kpi_8_1.at_risk_rate.flagged');
    }
}
