<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlightMediaIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_paginated_private_storage_safe_media_metadata(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_01_success')
            ->getJson('/api/v1/flights/'.$graph['flight_id'].'/media?per_page=2&page=1');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_media_01_success')
            ->assertJsonPath('meta', [
                'request_id' => 'req_media_01_success',
                'page' => 1,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.media_asset_id', $graph['alpha_media_id'])
            ->assertJsonPath('data.0.file_name', 'alpha.jpg')
            ->assertJsonPath('data.0.file_type', 'image')
            ->assertJsonPath('data.0.file_size_bytes', 1200)
            ->assertJsonPath('data.0.capture_location.type', 'Point')
            ->assertJsonPath('data.0.capture_location.coordinates.0', 123.9)
            ->assertJsonPath('data.0.capture_location.coordinates.1', 10.2)
            ->assertJsonPath('data.0.metadata.camera', 'wide')
            ->assertJsonPath('data.0.quality_score', '91.25')
            ->assertJsonPath('data.0.processing_status', 'completed');

        $this->assertSame([
            'media_asset_id',
            'flight_session_id',
            'uploaded_by_user_id',
            'file_name',
            'file_type',
            'mime_type',
            'file_size_bytes',
            'checksum_sha256',
            'capture_location',
            'captured_at',
            'metadata',
            'quality_score',
            'quality_status',
            'notes',
            'processing_status',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertArrayNotHasKey('storage_key', $response->json('data.0'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_composes_normalized_media_filters(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->getJson('/api/v1/flights/'.$graph['flight_id'].'/media?type= VIDEO &quality_status=NEEDS_RECAPTURE&processing_status=FAILED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media_asset_id', $graph['beta_media_id']);

        $this->withToken($graph['token'])
            ->getJson('/api/v1/flights/'.$graph['flight_id'].'/media?type=image&processing_status=queued')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media_asset_id', $graph['gamma_media_id']);
    }

    public function test_it_validates_media_filters_before_flight_lookup(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_01_validation')
            ->getJson('/api/v1/flights/'.Str::uuid().'/media?type=audio&quality_status=good&processing_status=ready&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.request_id', 'req_media_01_validation')
            ->assertJsonValidationErrors(
                ['type', 'quality_status', 'processing_status', 'page', 'per_page'],
                'error.details',
            );
    }

    public function test_it_hides_foreign_missing_and_malformed_flights(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_flight_id'], (string) Str::uuid(), 'not-a-uuid'] as $flightId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/flights/'.$flightId.'/media')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_it_enforces_authentication_and_tenant_valid_media_read_permission(): void
    {
        $graph = $this->createGraph(localPermission: false);

        $this->getJson('/api/v1/flights/'.$graph['flight_id'].'/media')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->withToken($graph['token'])
            ->getJson('/api/v1/flights/'.$graph['flight_id'].'/media')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.read');

        $foreignGrant = $this->createGraph('foreign-grant-', localPermission: false, foreignPermission: true);
        $this->withToken($foreignGrant['token'])
            ->getJson('/api/v1/flights/'.$foreignGrant['flight_id'].'/media')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.read');
    }

    public function test_it_rate_limits_media_lists(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/flights/'.$graph['flight_id'].'/media';

        $this->withToken($graph['token'])->getJson($uri)->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_01_throttled')
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_media_01_throttled');
    }

    public function test_it_versions_media_domains_and_read_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_064000_create_media_assets_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/012_media_asset_grants.sql'));

        $this->assertIsString($migration);
        $this->assertStringContainsString('media_assets_file_type_check', $migration);
        $this->assertStringContainsString('media_assets_quality_status_check', $migration);
        $this->assertStringContainsString('media_assets_processing_status_check', $migration);
        $this->assertStringContainsString('media_assets_checksum_check', $migration);
        $this->assertStringContainsString("geometry('capture_location', 'point', 4326)", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.media_assets TO mangroscan_api_rw, mangroscan_report_ro;',
            $dcl,
        );
        $this->assertStringNotContainsString('INSERT', $dcl);
        $this->assertStringNotContainsString('mangroscan_worker', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph('constraint-');
        $this->expectException(QueryException::class);
        DB::table('media_assets')
            ->where('media_asset_id', $graph['alpha_media_id'])
            ->update(['file_type' => 'audio']);
    }

    /**
     * @return array{
     *   actor_id: string,
     *   flight_id: string,
     *   foreign_flight_id: string,
     *   alpha_media_id: string,
     *   beta_media_id: string,
     *   gamma_media_id: string,
     *   token: string
     * }
     */
    private function createGraph(
        string $prefix = '',
        bool $localPermission = true,
        bool $foreignPermission = false,
    ): array {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $foreignRoleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Media Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Media Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'media-reader@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-media-reader@example.test');

        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Media Reader', 'role_code' => $prefix.'media_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Media Reader', 'role_code' => $prefix.'foreign_media_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'permission_id' => $permissionId,
            'permission_code' => $prefix.'media.read',
            'permission_name' => $prefix.'Read media',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($localPermission || $foreignPermission) {
            DB::table('role_permissions')->insert([
                'role_id' => $foreignPermission ? $foreignRoleId : $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $actorId,
                'role_id' => $foreignPermission ? $foreignRoleId : $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $siteId = (string) Str::uuid();
        $foreignSiteId = (string) Str::uuid();
        $this->insertSite($siteId, $organizationId, $actorId, $prefix.'MEDIA-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-MEDIA-SITE');

        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, $prefix.'MEDIA-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-MEDIA-MISSION');

        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, $prefix.'MEDIA-DRONE');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, $prefix.'FOREIGN-MEDIA-DRONE');

        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, $prefix.'MEDIA-FLIGHT');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, $prefix.'FOREIGN-MEDIA-FLIGHT');

        $alphaMediaId = (string) Str::uuid();
        $betaMediaId = (string) Str::uuid();
        $gammaMediaId = (string) Str::uuid();
        $this->insertMedia($alphaMediaId, $flightId, $actorId, $prefix.'alpha.jpg', 'image', 'acceptable', 'completed', '2026-08-12T02:00:00+00:00', false, true);
        $this->insertMedia($betaMediaId, $flightId, $actorId, $prefix.'beta.mp4', 'video', 'needs_recapture', 'failed', '2026-08-12T02:05:00+00:00');
        $this->insertMedia($gammaMediaId, $flightId, $actorId, $prefix.'gamma.jpg', 'image', 'pending', 'queued', '2026-08-12T02:10:00+00:00');
        $this->insertMedia((string) Str::uuid(), $flightId, $actorId, $prefix.'deleted.jpg', 'image', 'pending', 'pending', '2026-08-12T02:15:00+00:00', true);
        $this->insertMedia((string) Str::uuid(), $foreignFlightId, $foreignUserId, $prefix.'foreign.jpg', 'image', 'acceptable', 'completed', '2026-08-12T02:00:00+00:00');

        return [
            'actor_id' => $actorId,
            'flight_id' => $flightId,
            'foreign_flight_id' => $foreignFlightId,
            'alpha_media_id' => $alphaMediaId,
            'beta_media_id' => $betaMediaId,
            'gamma_media_id' => $gammaMediaId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'media-test')->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Media',
            'last_name' => 'Reader',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSite(string $id, string $organizationId, string $actorId, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id,
            'organization_id' => $organizationId,
            'site_name' => $code,
            'site_code' => $code,
            'province' => 'Negros Oriental',
            'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine',
            'status' => 'active',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMission(string $id, string $siteId, string $actorId, string $code): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id,
            'site_id' => $siteId,
            'mission_code' => $code,
            'mission_title' => $code,
            'mission_objective' => 'Capture test media.',
            'mission_status' => 'completed',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDrone(string $id, string $organizationId, string $serial): void
    {
        DB::table('drones')->insert([
            'drone_id' => $id,
            'organization_id' => $organizationId,
            'drone_name' => $serial,
            'model' => 'Test',
            'serial_number' => $serial,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertFlight(string $id, string $missionId, string $droneId, string $pilotId, string $code): void
    {
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $id,
            'mission_id' => $missionId,
            'drone_id' => $droneId,
            'pilot_user_id' => $pilotId,
            'flight_code' => $code,
            'flight_status' => 'completed',
            'quality_status' => 'acceptable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMedia(
        string $id,
        string $flightId,
        string $uploaderId,
        string $fileName,
        string $type,
        string $qualityStatus,
        string $processingStatus,
        string $capturedAt,
        bool $deleted = false,
        bool $withDetails = false,
    ): void {
        DB::table('media_assets')->insert([
            'media_asset_id' => $id,
            'flight_session_id' => $flightId,
            'uploaded_by_user_id' => $uploaderId,
            'file_name' => $fileName,
            'file_type' => $type,
            'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'file_size_bytes' => $withDetails ? 1200 : 2400,
            'storage_key' => 'private/'.$id.'/'.$fileName,
            'checksum_sha256' => str_repeat($withDetails ? 'a' : 'b', 64),
            'capture_location' => DB::getDriverName() === 'pgsql' ? null : json_encode(['type' => 'Point', 'coordinates' => [123.9, 10.2]], JSON_THROW_ON_ERROR),
            'captured_at' => $capturedAt,
            'metadata' => $withDetails ? json_encode(['camera' => 'wide'], JSON_THROW_ON_ERROR) : null,
            'quality_score' => $withDetails ? '91.25' : null,
            'quality_status' => $qualityStatus,
            'notes' => $withDetails ? 'Clear frame' : null,
            'processing_status' => $processingStatus,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE media_assets SET capture_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE media_asset_id = ?',
                [123.9, 10.2, $id],
            );
        }
    }
}
