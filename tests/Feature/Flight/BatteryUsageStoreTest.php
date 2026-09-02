<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class BatteryUsageStoreTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_records_audited_battery_usage_for_a_flight(): void
    {
        $graph = $this->graph(['flights.update'], 'battery-usage-');

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_bat_03')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/battery-usage', [
                'battery_id' => $graph['battery_id'],
                'start_percentage' => 100,
                'end_percentage' => 65,
                'usage_minutes' => 25,
                'notes' => 'Normal sortie battery usage',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.battery_id', $graph['battery_id'])
            ->assertJsonPath('data.start_percentage', '100.00')
            ->assertJsonPath('data.end_percentage', '65.00')
            ->assertJsonPath('data.usage_minutes', '25.00')
            ->assertJsonPath('meta.request_id', 'req_bat_03');

        $audit = AuditLog::query()->sole();
        $this->assertSame('battery_usage.create', $audit->action);
        $this->assertSame('req_bat_03', $audit->request_id);
    }

    public function test_it_validates_the_usage_payload(): void
    {
        $graph = $this->graph(['flights.update'], 'battery-validation-');

        $this->withToken($graph['token'])
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/battery-usage', [
                'battery_id' => 'bad',
                'start_percentage' => 150,
                'end_percentage' => -1,
                'usage_minutes' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'battery_id', 'start_percentage', 'end_percentage', 'usage_minutes',
            ], 'error.details');
    }

    public function test_it_rejects_foreign_retired_and_increasing_battery_usage(): void
    {
        $graph = $this->graph(['flights.update'], 'battery-conflict-');
        $foreign = $this->graph([], 'battery-foreign-');

        $this->withToken($graph['token'])->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            $this->payload($foreign['battery_id']),
        )->assertNotFound();

        DB::table('batteries')->where('battery_id', $graph['battery_id'])->update(['status' => 'retired']);
        $this->withToken($graph['token'])->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            $this->payload($graph['battery_id']),
        )->assertConflict();

        DB::table('batteries')->where('battery_id', $graph['battery_id'])->update(['status' => 'available']);
        $this->withToken($graph['token'])->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            [...$this->payload($graph['battery_id']), 'start_percentage' => 20, 'end_percentage' => 80],
        )->assertConflict();

        $this->assertDatabaseCount('battery_usages', 0);
    }

    public function test_it_requires_authentication_and_permission(): void
    {
        $graph = $this->graph([], 'battery-access-');

        $this->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            $this->payload($graph['battery_id']),
        )->assertUnauthorized();
        $this->withToken($graph['token'])->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            $this->payload($graph['battery_id']),
        )->assertForbidden();
    }

    public function test_it_rolls_back_audit_failure_and_versions_narrow_dcl(): void
    {
        $graph = $this->graph(['flights.update'], 'battery-rollback-');
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->postJson(
            '/api/v1/flights/'.$graph['flight_id'].'/battery-usage',
            $this->payload($graph['battery_id']),
        )->assertInternalServerError();
        $this->assertDatabaseCount('battery_usages', 0);

        $dcl = file_get_contents(database_path('sql/dcl/062_jessamae_endpoint_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('app.battery_usages', $dcl);
        $this->assertStringNotContainsString('GRANT UPDATE ON TABLE app.battery_usages', $dcl);
        $this->assertStringNotContainsString('GRANT DELETE ON TABLE app.battery_usages', $dcl);
    }

    /** @param list<string> $permissions @return array<string, string> */
    private function graph(array $permissions, string $prefix): array
    {
        $identity = $this->apiIdentity($permissions, $prefix);
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], Str::upper($prefix));
        $batteryId = (string) Str::uuid();
        DB::table('batteries')->insert([
            'battery_id' => $batteryId,
            'organization_id' => $identity['organization_id'],
            'battery_code' => 'BAT-'.Str::upper(Str::random(8)),
            'battery_type' => 'lipo',
            'capacity_mah' => 5000,
            'voltage' => 22.2,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [...$identity, ...$lineage, 'battery_id' => $batteryId];
    }

    /** @return array<string, mixed> */
    private function payload(string $batteryId): array
    {
        return [
            'battery_id' => $batteryId,
            'start_percentage' => 100,
            'end_percentage' => 50,
        ];
    }
}
