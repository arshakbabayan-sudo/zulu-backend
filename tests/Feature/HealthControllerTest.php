<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_health_returns_ok_envelope(): void
    {
        $res = $this->getJson('/api/health/deep');

        $res->assertOk();
        $res->assertJsonStructure([
            'status',
            'version',
            'checks' => ['db', 'cache', 'storage', 'migrations'],
            'latency_ms' => ['db_ms', 'cache_ms', 'storage_ms'],
            'pending_migrations',
            'time',
        ]);

        $this->assertSame('ok', $res->json('status'));
        $this->assertSame('ok', $res->json('checks.db'));
        $this->assertSame('ok', $res->json('checks.cache'));
        $this->assertSame('ok', $res->json('checks.storage'));
        // Right after RefreshDatabase ran, no pending migrations remain.
        $this->assertSame('ok', $res->json('checks.migrations'));
    }

    public function test_deep_health_does_not_require_auth(): void
    {
        $res = $this->getJson('/api/health/deep');
        $res->assertOk();
    }
}
