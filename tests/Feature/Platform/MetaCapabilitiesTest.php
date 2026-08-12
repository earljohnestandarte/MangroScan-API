<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MetaCapabilitiesTest extends TestCase
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

    // [SYS-02] Capabilities use the documented fields and standard envelope.
    public function test_it_returns_public_api_capabilities(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'req_sys_02_success')
            ->getJson('/api/v1/meta/capabilities');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_sys_02_success')
            ->assertExactJson([
                'data' => [
                    'api_version' => 'v1',
                    'features' => [
                        'health_checks' => true,
                        'request_ids' => true,
                    ],
                    'limits' => [
                        'pagination_per_page_max' => 100,
                    ],
                ],
                'meta' => [
                    'request_id' => 'req_sys_02_success',
                ],
            ]);
    }

    // [SYS-02] Capabilities return 503 when the SYS-01 readiness dependency fails.
    public function test_it_rejects_capability_discovery_when_a_dependency_is_unavailable(): void
    {
        config(['filesystems.default' => 'missing-capabilities-disk']);

        $response = $this
            ->withHeader('X-Request-ID', 'req_sys_02_failure')
            ->getJson('/api/v1/meta/capabilities');

        $response
            ->assertServiceUnavailable()
            ->assertHeader('X-Request-ID', 'req_sys_02_failure')
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.details.storage', 'unavailable')
            ->assertJsonPath('error.request_id', 'req_sys_02_failure')
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details' => ['db', 'storage', 'queue'],
                    'request_id',
                ],
            ]);
    }
}
