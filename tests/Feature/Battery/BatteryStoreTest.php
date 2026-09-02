<?php

namespace Tests\Feature\Battery;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class BatteryStoreTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_registers_an_audited_tenant_battery(): void
    {
        $identity = $this->apiIdentity(['batteries.manage'], 'battery-store-');

        $response = $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_bat_02')
            ->postJson('/api/v1/batteries', [
                'battery_code' => ' bat-field-01 ',
                'battery_type' => ' LIPO ',
                'capacity_mah' => '5000.50',
                'voltage' => '22.20',
                'status' => ' AVAILABLE ',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.organization_id', $identity['organization_id'])
            ->assertJsonPath('data.battery_code', 'BAT-FIELD-01')
            ->assertJsonPath('data.battery_type', 'lipo')
            ->assertJsonPath('data.capacity_mah', '5000.50')
            ->assertJsonPath('data.voltage', '22.20')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('meta.request_id', 'req_bat_02');

        $audit = AuditLog::query()->sole();
        $this->assertSame('battery.create', $audit->action);
        $this->assertSame('BAT-FIELD-01', $audit->new_values['battery_code']);
    }

    public function test_it_validates_and_rejects_duplicate_codes(): void
    {
        $identity = $this->apiIdentity(['batteries.manage'], 'battery-conflict-');
        $payload = [
            'battery_code' => 'BAT-DUPLICATE',
            'battery_type' => 'li-ion',
            'status' => 'charging',
        ];

        $this->withToken($identity['token'])->postJson('/api/v1/batteries', $payload)->assertCreated();
        $this->withToken($identity['token'])->postJson('/api/v1/batteries', $payload)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->withToken($identity['token'])->postJson('/api/v1/batteries', [
            'battery_code' => ' ',
            'battery_type' => 'lead',
            'capacity_mah' => 0,
            'voltage' => -1,
            'status' => 'broken',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'battery_code', 'battery_type', 'capacity_mah', 'voltage', 'status',
            ], 'error.details');
    }

    public function test_it_enforces_access_and_versions_write_dcl(): void
    {
        $identity = $this->apiIdentity([], 'battery-denied-');
        $payload = ['battery_code' => 'BAT-DENIED', 'battery_type' => 'nimh', 'status' => 'available'];

        $this->postJson('/api/v1/batteries', $payload)->assertUnauthorized();
        $this->withToken($identity['token'])->postJson('/api/v1/batteries', $payload)->assertForbidden();

        $dcl = file_get_contents(database_path('sql/dcl/062_jessamae_endpoint_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT INSERT ON TABLE app.batteries, app.environment_logs, app.battery_usages TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.batteries TO mangroscan_api_rw, mangroscan_report_ro;',
            $dcl,
        );
        $this->assertTrue(Str::contains($dcl, 'GRANT UPDATE (status, updated_at) ON TABLE app.batteries'));
        $this->assertDatabaseCount('batteries', 0);
    }
}
