<?php

namespace Tests\Feature;

use App\Filament\Pages\AiStatus;
use App\Filament\Resources\AiInsights\Pages\ListAiInsights;
use App\Models\AiInsight;
use App\Models\AiUsageRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiGovernance;
use App\Services\AiInsightService;
use App\Support\Ai\DataReference;
use App\Support\Chatbot\ChatReply;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel admin grup AI: halaman berisi data, izin ai.review / ai.manage, dan aksi tinjau & jeda lewat Livewire. */
class AiAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $coordinator;

    private User $marketing;

    private AiInsight $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        $this->coordinator = User::factory()->create(['role' => 'training_coordinator']);
        $this->marketing = User::factory()->create(['role' => User::ROLE_MARKETING]);
        $this->seed(RolesSeeder::class);

        $svc = app(AiInsightService::class);
        $this->draft = $svc->record('corporate_insight', ['summary' => 'Gap kompetensi SCADA di unit Engineering 18 poin'], (new DataReference)->add('organization', [], 1));
        $svc->record('recommendation', ['reason' => 'Anda lulus PLC Dasar, lanjut ke PLC Lanjutan'], new DataReference);
        app(AiGovernance::class)->recordUsage('public_chatbot', 'ok', new ChatReply('x', model: 'claude-opus-5-5', inputTokens: 1000, outputTokens: 500), 1200);
    }

    public function test_pages_render_with_data(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/ai/insight')->assertOk()->assertSee('Antrean tinjauan')->assertSee('Gap kompetensi SCADA')->assertSee('Insight perusahaan');
        $this->get('/admin/ai/insight?tab=approved')->assertOk()->assertSee('Anda lulus PLC Dasar');
        $this->get('/admin/ai/status')->assertOk()
            ->assertSee('Status fitur AI')
            ->assertSee('Peringatan dini peserta berisiko')
            ->assertSee('Belum tersedia')
            ->assertSee('Data belum cukup')
            ->assertSee('Fitur AI dimatikan (AI_ENABLED=false)')
            ->assertSee('Jeda');
        $this->get('/admin/ai/pemakaian')->assertOk()->assertSee('Chatbot informasi publik')->assertSee('US$ 0,0140')->assertSee('Biaya 30 hari');
    }

    public function test_permissions(): void
    {
        foreach (['ai/insight', 'ai/status', 'ai/pemakaian'] as $slug) {
            $this->actingAs($this->marketing)->get("/admin/$slug")->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
        // Koordinator (ai.review) boleh meninjau, tapi tidak bisa menjeda use case (ai.manage).
        $this->actingAs($this->coordinator)->get('/admin/ai/status')->assertOk()->assertDontSee('Jeda');
        Livewire::test(AiStatus::class)->assertTableActionHidden('pause', 'feedback_analysis');
        Livewire::test(ListAiInsights::class)->assertTableActionVisible('approve', $this->draft);
    }

    public function test_review_and_pause_actions(): void
    {
        $this->actingAs($this->coordinator);
        Livewire::test(ListAiInsights::class)->callTableAction('approve', $this->draft)->assertHasNoTableActionErrors();
        $this->assertSame(AiInsight::STATUS_APPROVED, $this->draft->fresh()->status);

        Livewire::test(ListAiInsights::class, ['activeTab' => 'approved'])
            ->callTableAction('reject', $this->draft, ['rejection_reason' => 'pendek'])->assertHasTableActionErrors(['rejection_reason']);
        Livewire::test(ListAiInsights::class, ['activeTab' => 'approved'])
            ->callTableAction('reject', $this->draft, ['rejection_reason' => 'Data unit sudah berubah bulan ini'])->assertHasNoTableActionErrors();
        $this->assertSame(AiInsight::STATUS_REJECTED, $this->draft->fresh()->status);

        $this->actingAs($this->admin);
        Livewire::test(AiStatus::class)->callTableAction('pause', 'feedback_analysis', ['note' => 'Uji jeda'])->assertHasNoTableActionErrors();
        $this->assertTrue(app(AiGovernance::class)->isPaused('feedback_analysis'));
        Livewire::test(AiStatus::class)->callTableAction('resume', 'feedback_analysis')->assertHasNoTableActionErrors();
        $this->assertFalse(app(AiGovernance::class)->isPaused('feedback_analysis'));
        $this->assertSame(['ai.insight_approved', 'ai.insight_rejected', 'ai.use_case_paused', 'ai.use_case_resumed'],
            AuditLog::query()->where('action', 'like', 'ai.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AiUsageRecord::query()->count());
    }
}
