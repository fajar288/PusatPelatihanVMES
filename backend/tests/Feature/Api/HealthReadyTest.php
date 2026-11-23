<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\HealthCheckController;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** /api/v1/health/ready: status per komponen tanpa informasi sensitif, 503 bila database atau cron mati. */
class HealthReadyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ready_reports_components_without_sensitive_details(): void
    {
        $res = $this->getJson('/api/v1/health/ready')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.storage', 'ok')
            ->assertJsonPath('checks.scheduler', 'unknown')
            ->assertHeader('Cache-Control', 'no-store, private');
        $body = (string) $res->getContent();
        foreach ([app()->version(), (string) config('database.connections.'.config('database.default').'.database'), (string) config('app.name'), 'sqlite', 'mysql', 'pgsql'] as $secret) {
            if ($secret !== '' && $secret !== ':memory:') {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertSame(['status', 'checks', 'time'], array_keys($res->json()));
    }

    public function test_scheduler_heartbeat_and_stale_cron_returns_503(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00');
        // jalankan scheduler sekali: detak tercatat
        Artisan::call('schedule:run');
        $this->assertNotNull(Cache::get(HealthCheckController::HEARTBEAT_KEY));
        $this->getJson('/api/v1/health/ready')->assertOk()->assertJsonPath('checks.scheduler', 'ok');

        // cron berhenti 10 menit
        Carbon::setTestNow('2026-11-24 10:10:00');
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('status', 'fail')->assertJsonPath('checks.scheduler', 'fail');
    }

    public function test_queue_backlog_is_a_warning_not_an_outage(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00');
        config(['queue.default' => 'database']);
        HealthCheckController::beat();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);

        $this->getJson('/api/v1/health/ready')->assertOk()->assertJsonPath('status', 'warn')->assertJsonPath('checks.queue', 'warn');
    }

    public function test_database_down_returns_503(): void
    {
        HealthCheckController::beat();
        config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']]);
        $original = config('database.default');
        config(['database.default' => 'broken']);
        DB::purge('broken');

        try {
            $this->getJson('/api/v1/health/ready')->assertStatus(503)->assertJsonPath('checks.database', 'fail');
        } finally {
            config(['database.default' => $original]); // agar rollback RefreshDatabase memakai koneksi tes
        }
    }
}
