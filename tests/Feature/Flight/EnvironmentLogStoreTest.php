<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class EnvironmentLogStoreTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_appends_an_audited_environment_observation(): void
    {
        $identity = $this->apiIdentity(['flights.update'], 'environment-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'ENV');

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_env_01')
            ->postJson('/api/v1/flights/'.$lineage['flight_id'].'/environment-logs', [
                'recorded_at' => '2026-09-02T08:30:00+08:00',
                'weather_condition' => 'Partly cloudy',
                'wind_speed_mps' => '3.25',
                'temperature_celsius' => '29.50',
                'humidity_percent' => '78.25',
                'visibility_status' => 'good',
                'notes' => 'Safe field conditions',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.flight_session_id', $lineage['flight_id'])
            ->assertJsonPath('data.weather_condition', 'Partly cloudy')
            ->assertJsonPath('data.wind_speed_mps', '3.25')
            ->assertJsonPath('data.humidity_percent', '78.25')
            ->assertJsonPath('meta.request_id', 'req_env_01');

        $audit = AuditLog::query()->sole();
        $this->assertSame('environment_log.create', $audit->action);
    }

    public function test_it_validates_scope_and_access(): void
    {
        $identity = $this->apiIdentity(['flights.update'], 'environment-access-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'ENV-ACCESS');
        $payload = ['recorded_at' => 'bad', 'weather_condition' => '', 'wind_speed_mps' => -1, 'humidity_percent' => 101];

        $this->postJson('/api/v1/flights/'.$lineage['flight_id'].'/environment-logs', $payload)
            ->assertUnauthorized();
        $denied = $this->apiIdentity([], 'environment-denied-');
        $deniedLineage = $this->missionLineage($denied['organization_id'], $denied['actor_id'], 'ENV-DENIED');
        $this->withToken($denied['token'])
            ->postJson('/api/v1/flights/'.$deniedLineage['flight_id'].'/environment-logs', $payload)
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($identity['token'])
            ->postJson('/api/v1/flights/'.$lineage['flight_id'].'/environment-logs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'recorded_at', 'weather_condition', 'wind_speed_mps', 'humidity_percent',
            ], 'error.details');

        $foreign = $this->apiIdentity([], 'environment-foreign-');
        $foreignLineage = $this->missionLineage($foreign['organization_id'], $foreign['actor_id'], 'ENV-FOREIGN');
        $this->withToken($identity['token'])->postJson(
            '/api/v1/flights/'.$foreignLineage['flight_id'].'/environment-logs',
            ['recorded_at' => now()->toIso8601String(), 'weather_condition' => 'Clear'],
        )->assertNotFound();
    }

    public function test_it_versions_environment_write_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/062_jessamae_endpoint_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.environment_logs', $dcl);
        $this->assertStringNotContainsString('GRANT UPDATE ON TABLE app.environment_logs', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE ON TABLE app.environment_logs', $dcl);
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $identity = $this->apiIdentity(['flights.update'], 'environment-rollback-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'ENV-ROLLBACK');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($identity['token'])->postJson(
            '/api/v1/flights/'.$lineage['flight_id'].'/environment-logs',
            ['recorded_at' => now()->toIso8601String(), 'weather_condition' => 'Clear'],
        )->assertInternalServerError();
        $this->assertDatabaseCount('environment_logs', 0);
    }
}
