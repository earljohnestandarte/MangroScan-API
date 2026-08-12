<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
        ]);

        DB::purge('sqlite');
    }

    // [SYS-01] Healthy dependencies follow the documented response contract.
    public function test_it_reports_api_dependency_readiness(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'req_sys_01_success')
            ->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_sys_01_success')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.db', 'ok')
            ->assertJsonPath('data.storage', 'ok')
            ->assertJsonPath('data.queue', 'ok')
            ->assertJsonPath('meta.request_id', 'req_sys_01_success')
            ->assertJsonStructure([
                'data' => ['status', 'db', 'storage', 'queue', 'time'],
                'meta' => ['request_id'],
            ]);

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertSame(
            ['status', 'db', 'storage', 'queue', 'time'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['request_id'], array_keys($response->json('meta')));
    }

    // [SYS-01] A failed dependency returns the documented 503 error.
    public function test_it_reports_an_unavailable_dependency(): void
    {
        config(['filesystems.default' => 'missing-health-disk']);

        $response = $this
            ->withHeader('X-Request-ID', 'req_sys_01_failure')
            ->getJson('/api/v1/health');

        $response
            ->assertServiceUnavailable()
            ->assertHeader('X-Request-ID', 'req_sys_01_failure')
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.details.status', 'unavailable')
            ->assertJsonPath('error.details.storage', 'unavailable')
            ->assertJsonPath('error.request_id', 'req_sys_01_failure')
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details' => ['status', 'db', 'storage', 'queue', 'time'],
                    'request_id',
                ],
            ]);

        $this->assertSame(['error'], array_keys($response->json()));
        $this->assertSame(
            ['code', 'message', 'details', 'request_id'],
            array_keys($response->json('error')),
        );
    }

    // [SYS-01] The health contract is only exposed through API v1.
    public function test_it_does_not_expose_an_unversioned_health_route(): void
    {
        $this->getJson('/api/health')->assertNotFound();
    }
}
