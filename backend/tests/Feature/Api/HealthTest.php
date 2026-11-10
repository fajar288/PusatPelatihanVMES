<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('version', 'v1')
            ->assertJsonStructure(['status', 'app', 'version', 'laravel', 'time']);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_cors_allows_frontend_origin(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
