<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FlightUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [FLT-04] Planning metadata updates with normalized before/after audit evidence.
    public function test_it_updates_planned_flight_metadata(): void
    {
        $g = $this->graph();
        $response = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_flt_04')
            ->patchJson('/api/v1/flights/'.$g['flight'], [
                'drone_id' => $g['replacement_drone'], 'pilot_user_id' => $g['replacement_pilot'],
                'flight_code' => ' updated-flight ', 'planned_altitude_meters' => '75.25',
                'notes' => ' Updated route plan ',
            ]);

        $response->assertOk()->assertJsonPath('data.drone_id', $g['replacement_drone'])
            ->assertJsonPath('data.pilot_user_id', $g['replacement_pilot'])
            ->assertJsonPath('data.flight_code', 'UPDATED-FLIGHT')
            ->assertJsonPath('data.planned_altitude_meters', '75.25')
            ->assertJsonPath('data.notes', 'Updated route plan')
            ->assertJsonPath('data.status', 'planned')->assertJsonPath('data.quality_status', 'pending')
            ->assertJsonPath('meta.request_id', 'req_flt_04');
        $audit = AuditLog::query()->sole();
        $this->assertSame('flight.update', $audit->action);
        $this->assertSame($g['original_code'], $audit->old_values['flight_code']);
        $this->assertSame('UPDATED-FLIGHT', $audit->new_values['flight_code']);
    }

    public function test_it_supports_individual_updates_and_explicit_null_clearing(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], [
            'planned_altitude_meters' => null, 'notes' => null,
        ])->assertOk()->assertJsonPath('data.planned_altitude_meters', null)->assertJsonPath('data.notes', null);
    }

    public function test_it_requires_at_least_one_valid_planning_field(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], [])
            ->assertUnprocessable()->assertJsonValidationErrors(['request'], 'error.details');
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], [
            'drone_id' => 'bad', 'pilot_user_id' => 'bad', 'flight_code' => ' ',
            'planned_altitude_meters' => '1000000', 'notes' => ['bad'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'drone_id', 'pilot_user_id', 'flight_code', 'planned_altitude_meters', 'notes',
        ], 'error.details');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_duplicate_codes_and_unavailable_resources(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['flight_code' => ' '.$g['reserved_code'].' '])
            ->assertConflict()->assertJsonPath('error.details.flight_code', $g['reserved_code']);
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['drone_id' => $g['maintenance_drone']])
            ->assertConflict()->assertJsonPath('error.details.current_status', 'maintenance');
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['pilot_user_id' => $g['inactive_pilot']])
            ->assertConflict()->assertJsonPath('error.details.pilot_user_id', $g['inactive_pilot']);
    }

    public function test_it_hides_foreign_resource_ids(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['drone_id' => $g['foreign_drone']])
            ->assertNotFound();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['pilot_user_id' => $g['foreign_pilot']])
            ->assertNotFound();
    }

    public function test_it_rejects_non_planned_flights(): void
    {
        foreach (['flying', 'completed', 'aborted', 'failed'] as $status) {
            $g = $this->graph(status: $status);
            $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['notes' => 'No'])
                ->assertConflict()->assertJsonPath('error.details.current_status', $status);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_hides_foreign_deleted_lineage_missing_and_malformed_flights(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_flight'], $g['deleted_flight'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$id, ['notes' => 'No'])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $g = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['flight_code' => 'CHANGED'])
            ->assertInternalServerError();
        $this->assertDatabaseHas('flight_sessions', ['flight_session_id' => $g['flight'], 'flight_code' => $g['original_code']]);
    }

    public function test_it_enforces_active_authentication_and_tenant_permission(): void
    {
        $g = $this->graph(permission: false);
        $this->patchJson('/api/v1/flights/'.$g['flight'], ['notes' => 'No'])->assertUnauthorized();
        $this->withToken($g['token'])->patchJson('/api/v1/flights/'.$g['flight'], ['notes' => 'No'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'flights.update');

        $this->app['auth']->forgetGuards();
        $foreign = $this->graph(permissionOrganization: 'foreign');
        $this->withToken($foreign['token'])->patchJson('/api/v1/flights/'.$foreign['flight'], ['notes' => 'No'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'flights.update');
    }

    public function test_it_rate_limits_updates_and_reuses_existing_dcl(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $g = $this->graph();
        $url = '/api/v1/flights/'.$g['flight'];
        $this->withToken($g['token'])->patchJson($url, ['notes' => 'One'])->assertOk();
        $this->withToken($g['token'])->patchJson($url, ['notes' => 'Two'])->assertTooManyRequests();

        $dcl = file_get_contents(database_path('sql/dcl/008_flight_session_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT, UPDATE ON TABLE app.flight_sessions TO mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    /** @return array<string, string> */
    private function graph(string $status = 'planned', bool $permission = true, ?string $permissionOrganization = null): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $pilot = (string) Str::uuid();
        $replacementPilot = (string) Str::uuid();
        $inactivePilot = (string) Str::uuid();
        $foreignPilot = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Flight Update '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Flight Update '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach ([[$actor, $org, 'active'], [$pilot, $org, 'active'], [$replacementPilot, $org, 'active'], [$inactivePilot, $org, 'inactive'], [$foreignPilot, $foreignOrg, 'active']] as [$id, $organizationId, $userStatus]) {
            $this->user($id, $organizationId, $userStatus);
        }
        $permissionId = DB::table('permissions')->where('permission_code', 'flights.update')->value('permission_id') ?? (string) Str::uuid();
        $role = (string) Str::uuid();
        DB::table('roles')->insert([
            'role_id' => $role, 'organization_id' => $permissionOrganization === 'foreign' ? $foreignOrg : $org,
            'role_name' => 'Flight Updater', 'role_code' => 'flight-updater-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! DB::table('permissions')->where('permission_id', $permissionId)->exists()) {
            DB::table('permissions')->insert(['permission_id' => $permissionId, 'permission_code' => 'flights.update', 'permission_name' => 'Update flights', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        $deletedSite = (string) Str::uuid();
        $this->site($site, $org, $actor, 'FLT-UPD-SITE-'.$suffix);
        $this->site($foreignSite, $foreignOrg, $foreignPilot, 'FOREIGN-FLT-UPD-SITE-'.$suffix);
        $this->site($deletedSite, $org, $actor, 'DELETED-FLT-UPD-SITE-'.$suffix, true);
        $mission = (string) Str::uuid();
        $foreignMission = (string) Str::uuid();
        $deletedMission = (string) Str::uuid();
        $this->mission($mission, $site, $actor, 'FLT-UPD-MSN-'.$suffix);
        $this->mission($foreignMission, $foreignSite, $foreignPilot, 'FOREIGN-FLT-UPD-MSN-'.$suffix);
        $this->mission($deletedMission, $deletedSite, $actor, 'DELETED-FLT-UPD-MSN-'.$suffix);
        $drone = (string) Str::uuid();
        $replacementDrone = (string) Str::uuid();
        $maintenanceDrone = (string) Str::uuid();
        $foreignDrone = (string) Str::uuid();
        $this->drone($drone, $org, 'Original '.$suffix, 'ORIGINAL-'.$suffix, 'available');
        $this->drone($replacementDrone, $org, 'Replacement '.$suffix, 'REPLACEMENT-'.$suffix, 'available');
        $this->drone($maintenanceDrone, $org, 'Maintenance '.$suffix, 'MAINT-'.$suffix, 'maintenance');
        $this->drone($foreignDrone, $foreignOrg, 'Foreign '.$suffix, 'FOREIGN-'.$suffix, 'available');
        $flight = (string) Str::uuid();
        $foreignFlight = (string) Str::uuid();
        $deletedFlight = (string) Str::uuid();
        $originalCode = 'ORIGINAL-FLIGHT-'.$suffix;
        $reservedCode = 'RESERVED-FLIGHT-'.$suffix;
        $this->flight($flight, $mission, $drone, $pilot, $originalCode, $status);
        $this->flight((string) Str::uuid(), $mission, $drone, $pilot, $reservedCode, 'planned');
        $this->flight($foreignFlight, $foreignMission, $foreignDrone, $foreignPilot, 'FOREIGN-FLIGHT-'.$suffix, 'planned');
        $this->flight($deletedFlight, $deletedMission, $drone, $pilot, 'DELETED-FLIGHT-'.$suffix, 'planned');

        return [
            'flight' => $flight, 'foreign_flight' => $foreignFlight, 'deleted_flight' => $deletedFlight,
            'replacement_drone' => $replacementDrone, 'maintenance_drone' => $maintenanceDrone,
            'foreign_drone' => $foreignDrone, 'replacement_pilot' => $replacementPilot,
            'inactive_pilot' => $inactivePilot, 'foreign_pilot' => $foreignPilot,
            'original_code' => $originalCode, 'reserved_code' => $reservedCode,
            'token' => User::query()->findOrFail($actor)->createToken('flight-update')->plainTextToken,
        ];
    }

    private function user(string $id, string $organizationId, string $status): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId, 'first_name' => 'Flight', 'last_name' => 'Updater',
            'email' => Str::uuid().'@test', 'password' => Hash::make('password'), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $id, string $organizationId, string $creator, string $code, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organizationId, 'site_name' => $code, 'site_code' => $code,
            'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active',
            'created_by' => $creator, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function mission(string $id, string $siteId, string $creator, string $code): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $siteId, 'mission_code' => $code, 'mission_title' => $code,
            'mission_objective' => 'Update planned flight', 'mission_status' => 'planned', 'created_by' => $creator,
            'approved_by' => $creator, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function drone(string $id, string $organizationId, string $name, string $serial, string $status): void
    {
        DB::table('drones')->insert([
            'drone_id' => $id, 'organization_id' => $organizationId, 'drone_name' => $name,
            'serial_number' => $serial, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function flight(string $id, string $missionId, string $droneId, string $pilotId, string $code, string $status): void
    {
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $id, 'mission_id' => $missionId, 'drone_id' => $droneId,
            'pilot_user_id' => $pilotId, 'flight_code' => $code, 'planned_altitude_meters' => 50,
            'flight_status' => $status, 'quality_status' => 'pending', 'notes' => 'Original route plan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
