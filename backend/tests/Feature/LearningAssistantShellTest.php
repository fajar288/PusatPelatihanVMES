<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\ChatLog;
use App\Models\User;
use App\Support\Chatbot\ChatModel;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

/**
 * Roadmap 5 tahap 5G — kerangka Asisten Belajar (keputusan mengikat nomor 4): panel "Belum tersedia", flag
 * AI_CHATBOT_ENABLED terpisah dari chatbot publik, endpoint 503 ai_unavailable, tabel ai_insights kosong,
 * dan tidak ada panggilan API AI / data peserta yang terkirim.
 */
class LearningAssistantShellTest extends TestCase
{
    use RefreshDatabase;

    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->model = new FakeChatModel;
        $this->app->instance(ChatModel::class, $this->model);
        Http::fake();
    }

    private function member(): User
    {
        $user = User::factory()->create(['email' => 'peserta@example.com']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_flag_is_off_by_default_and_status_says_not_available(): void
    {
        $this->assertFalse(config('ai.assistant.enabled'));
        $this->member();

        $this->getJson('/api/v1/me/assistant')->assertOk()->assertExactJson(['data' => [
            'enabled' => false,
            'available' => false,
            'status' => 'unavailable',
            'label' => 'Belum tersedia',
            'message' => 'Asisten Belajar sedang disiapkan. Untuk pertanyaan materi, hubungi trainer lewat forum diskusi kelas atau tim admin.',
        ]]);
    }

    public function test_sending_a_question_returns_503_ai_unavailable_without_calling_any_ai_or_storing_data(): void
    {
        $this->member();
        $question = ['message' => 'Apa beda OSPF dan EIGRP? Nilai kuis saya 55, NIK 3201xxxx.', 'lessonId' => 12];

        $this->postJson('/api/v1/me/assistant/messages', $question)
            ->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'ai_unavailable', 'message' => 'Asisten Belajar belum tersedia.']]);

        // PRD skenario: flag dinyalakan sebelum ada keputusan baru → tetap kerangka, tetap tidak ada panggilan AI.
        config(['ai.assistant.enabled' => true, 'chatbot.enabled' => true, 'chatbot.api_key' => 'sk-test']);
        $this->getJson('/api/v1/me/assistant')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/me/assistant/messages', $question)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable');

        $this->assertCount(0, $this->model->calls);
        Http::assertNothingSent();
        $this->assertSame(0, ChatLog::count());
        $this->assertSame(0, AiInsight::count());
    }

    public function test_endpoints_require_a_signed_in_member(): void
    {
        $this->getJson('/api/v1/me/assistant')->assertUnauthorized();
        $this->postJson('/api/v1/me/assistant/messages', ['message' => 'halo'])->assertUnauthorized();
    }

    public function test_public_chatbot_flag_is_independent_of_the_assistant_flag(): void
    {
        config(['ai.assistant.enabled' => true]);
        $this->getJson('/api/v1/chat/status')->assertOk()->assertJson(['enabled' => false]);

        config(['ai.assistant.enabled' => false, 'chatbot.enabled' => true, 'chatbot.api_key' => 'sk-test']);
        $this->getJson('/api/v1/chat/status')->assertJson(['enabled' => true]);
        $this->member();
        $this->getJson('/api/v1/me/assistant')->assertJsonPath('data.enabled', false);
    }

    public function test_ai_insights_table_has_governance_columns_and_starts_empty(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_insights', ['use_case', 'model_version', 'prompt_version', 'data_reference', 'reviewer_id', 'reviewed_at']));
        $this->assertSame(0, AiInsight::count());

        $reviewer = User::factory()->create();
        $insight = AiInsight::create([
            'use_case' => 'learning_assistant',
            'model_version' => 'claude-opus-5-5',
            'prompt_version' => '2026-11-16.1',
            'data_reference' => ['lesson_ids' => [12], 'hash' => hash('sha256', 'lesson-12')],
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
        ])->fresh();

        $this->assertSame([12], $insight->data_reference['lesson_ids']);
        $this->assertTrue($insight->reviewer->is($reviewer));

        $reviewer->delete();
        $this->assertNull($insight->fresh()->reviewer_id);
    }
}
