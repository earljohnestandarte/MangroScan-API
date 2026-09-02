<?php

namespace Tests\Feature\Database;

use App\Http\Middleware\EnsureActiveIdentity;
use Database\Seeders\RbacSeedData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class JessamaeEndpointInfrastructureTest extends TestCase
{
    public function test_all_owned_routes_have_the_expected_security_boundary(): void
    {
        $id = (string) Str::uuid();
        $endpoints = [
            ['PATCH', "/api/v1/drones/{$id}", 'permission:drones.manage'],
            ['PATCH', "/api/v1/sensors/{$id}", 'permission:sensors.manage'],
            ['POST', "/api/v1/sensors/{$id}/calibrations", 'permission:sensor_calibrations.manage'],
            ['GET', '/api/v1/batteries', 'permission:batteries.read'],
            ['POST', '/api/v1/batteries', 'permission:batteries.manage'],
            ['DELETE', "/api/v1/missions/{$id}", 'permission:missions.delete'],
            ['POST', "/api/v1/flights/{$id}/environment-logs", 'permission:flights.update'],
            ['POST', "/api/v1/flights/{$id}/battery-usage", 'permission:flights.update'],
            ['POST', '/api/v1/mobile/sync', null],
            ['GET', '/api/v1/mobile/sync/status', null],
            ['POST', "/api/v1/media/{$id}/download", 'permission:media.read'],
            ['DELETE', "/api/v1/media/{$id}", 'permission:media.delete'],
        ];

        foreach ($endpoints as [$method, $uri, $permission]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "{$method} {$uri}");
            $this->assertContains(EnsureActiveIdentity::class, $middleware, "{$method} {$uri}");
            $this->assertContains('throttle:auth.authenticated', $middleware, "{$method} {$uri}");
            if ($permission !== null) {
                $this->assertContains($permission, $middleware, "{$method} {$uri}");
            }
        }
    }

    public function test_endpoint_permissions_are_seeded_for_intended_roles(): void
    {
        foreach ([
            'drones.manage', 'sensors.manage', 'sensor_calibrations.manage',
            'batteries.read', 'batteries.manage', 'missions.delete',
            'flights.update', 'media.read', 'media.delete',
        ] as $permission) {
            $this->assertArrayHasKey($permission, RbacSeedData::PERMISSIONS);
        }

        $this->assertContains('batteries.manage', RbacSeedData::ROLE_PERMISSIONS['system_administrator']);
        $this->assertContains('media.delete', RbacSeedData::ROLE_PERMISSIONS['system_administrator']);
        $this->assertContains('media.delete', RbacSeedData::ROLE_PERMISSIONS['researcher']);
        $this->assertContains('media.delete', RbacSeedData::ROLE_PERMISSIONS['drone_operator']);
        $this->assertContains('missions.delete', RbacSeedData::ROLE_PERMISSIONS['environmental_specialist']);
    }

    public function test_endpoint_dcl_is_least_privilege_and_transactional(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/062_jessamae_endpoint_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('\\set ON_ERROR_STOP on', $dcl);
        $this->assertStringContainsString('BEGIN;', $dcl);
        $this->assertStringContainsString('ON TABLE app.drones TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('ON TABLE app.drone_sensors TO mangroscan_api_rw;', $dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.batteries', $dcl);
        $this->assertStringContainsString('GRANT INSERT ON TABLE app.batteries, app.environment_logs, app.battery_usages', $dcl);
        $this->assertStringContainsString('GRANT UPDATE (sync_version, deleted_at, updated_at)', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE', $dcl);
        $this->assertStringNotContainsString('TO PUBLIC', $dcl);
        $this->assertStringContainsString('COMMIT;', $dcl);
    }
}
