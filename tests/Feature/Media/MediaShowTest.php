<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaShowTest extends TestCase
{
    use RefreshDatabase;

    // [MEDIA-04] Tenant readers receive the exact existing private-safe MediaAsset shape.
    public function test_it_returns_metadata_without_a_storage_pointer(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_04_success')
            ->getJson('/api/v1/media/'.$graph['media_id']);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_media_04_success')
            ->assertJsonPath('meta.request_id', 'req_media_04_success')
            ->assertJsonPath('data.media_asset_id', $graph['media_id'])
            ->assertJsonPath('data.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.file_name', 'detail.jpg')
            ->assertJsonPath('data.file_type', 'image')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.file_size_bytes', 4096)
            ->assertJsonPath('data.capture_location.type', 'Point')
            ->assertJsonPath('data.capture_location.coordinates.0', 123.305278)
            ->assertJsonPath('data.capture_location.coordinates.1', 9.306944)
            ->assertJsonPath('data.metadata.camera', 'wide')
            ->assertJsonPath('data.quality_score', '94.50')
            ->assertJsonPath('data.quality_status', 'acceptable')
            ->assertJsonPath('data.processing_status', 'completed');

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
        ], array_keys($response->json('data')));
        foreach (['storage_key', 'url', 'preview_url', 'download_url', 'token', 'expires_at'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $response->json('data'));
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MEDIA-04] Foreign, deleted, missing, malformed and deleted-parent lineage is non-enumerable.
    public function test_it_hides_unavailable_media(): void
    {
        $graph = $this->createGraph();

        foreach ([$graph['foreign_media_id'], $graph['deleted_media_id'], (string) Str::uuid(), 'not-a-uuid'] as $mediaId) {
            $this->withToken($graph['token'])
                ->getJson('/api/v1/media/'.$mediaId)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $deletedParent = $this->createGraph('deleted-parent-');
        DB::table('survey_sites')->where('site_id', $deletedParent['site_id'])->update(['deleted_at' => now()]);

        $this->withToken($deletedParent['token'])
            ->getJson('/api/v1/media/'.$deletedParent['media_id'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // [MEDIA-04] Authentication, active identity and tenant-valid media.read are mandatory.
    public function test_it_enforces_access(): void
    {
        $missingPermission = $this->createGraph('missing-', localPermission: false);

        $this->getJson('/api/v1/media/'.$missingPermission['media_id'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->withToken($missingPermission['token'])
            ->getJson('/api/v1/media/'.$missingPermission['media_id'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.read');

        $foreignGrant = $this->createGraph('foreign-grant-', localPermission: false, foreignPermission: true);
        $this->withToken($foreignGrant['token'])
            ->getJson('/api/v1/media/'.$foreignGrant['media_id'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.read');

        $inactive = $this->createGraph('inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->withToken($inactive['token'])
            ->getJson('/api/v1/media/'.$inactive['media_id'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // [MEDIA-04] Detail reads share the authenticated request budget.
    public function test_it_rate_limits_media_detail(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/media/'.$graph['media_id'];

        $this->withToken($graph['token'])->getJson($uri)->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_04_throttled')
            ->getJson($uri)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_media_04_throttled');
    }

    // [MEDIA-04] Metadata detail reuses the existing SELECT-only media DCL.
    public function test_it_reuses_read_only_media_dcl(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/012_media_asset_grants.sql'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Media/MediaShowController.php'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT ON TABLE app.media_assets TO mangroscan_api_rw, mangroscan_report_ro;',
            $dcl,
        );
        foreach (['INSERT', 'UPDATE', 'DELETE', 'mangroscan_worker'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
        $this->assertIsString($controller);
        foreach (['Storage::', 'temporaryUrl', 'storage_key', 'download_url', 'preview_url'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $controller);
        }
    }

    /**
     * @return array{
     *   actor_id: string,
     *   site_id: string,
     *   flight_id: string,
     *   media_id: string,
     *   foreign_media_id: string,
     *   deleted_media_id: string,
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
        $permissionId = DB::table('permissions')->where('permission_code', 'media.read')->value('permission_id');

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Media Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Media Detail Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'media-detail@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-media-detail@example.test');

        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Media Detail Reader', 'role_code' => $prefix.'media_detail_reader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Media Detail Reader', 'role_code' => $prefix.'foreign_media_detail_reader', 'created_at' => now(), 'updated_at' => now()],
        ]);

        if (! is_string($permissionId)) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => 'media.read',
                'permission_name' => 'Read media',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
        $this->insertSite($siteId, $organizationId, $actorId, $prefix.'MEDIA-DETAIL-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-MEDIA-DETAIL-SITE');

        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, $prefix.'MEDIA-DETAIL-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-MEDIA-DETAIL-MISSION');

        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, $prefix.'MEDIA-DETAIL-DRONE');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, $prefix.'FOREIGN-MEDIA-DETAIL-DRONE');

        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, $prefix.'MEDIA-DETAIL-FLIGHT');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, $prefix.'FOREIGN-MEDIA-DETAIL-FLIGHT');

        $mediaId = (string) Str::uuid();
        $foreignMediaId = (string) Str::uuid();
        $deletedMediaId = (string) Str::uuid();
        $this->insertMedia($mediaId, $flightId, $actorId, 'detail.jpg');
        $this->insertMedia($foreignMediaId, $foreignFlightId, $foreignUserId, 'foreign-detail.jpg');
        $this->insertMedia($deletedMediaId, $flightId, $actorId, 'deleted-detail.jpg', deleted: true);

        return [
            'actor_id' => $actorId,
            'site_id' => $siteId,
            'flight_id' => $flightId,
            'media_id' => $mediaId,
            'foreign_media_id' => $foreignMediaId,
            'deleted_media_id' => $deletedMediaId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'media-detail')->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Media',
            'last_name' => 'Detail',
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
            'mission_objective' => 'Inspect captured metadata.',
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
        bool $deleted = false,
    ): void {
        DB::table('media_assets')->insert([
            'media_asset_id' => $id,
            'flight_session_id' => $flightId,
            'uploaded_by_user_id' => $uploaderId,
            'file_name' => $fileName,
            'file_type' => 'image',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 4096,
            'storage_key' => 'private/'.$id.'/'.$fileName,
            'checksum_sha256' => str_repeat('a', 64),
            'capture_location' => DB::getDriverName() === 'pgsql'
                ? null
                : json_encode(['type' => 'Point', 'coordinates' => [123.305278, 9.306944]], JSON_THROW_ON_ERROR),
            'captured_at' => '2026-08-12T02:50:00+00:00',
            'metadata' => json_encode(['camera' => 'wide'], JSON_THROW_ON_ERROR),
            'quality_score' => '94.50',
            'quality_status' => 'acceptable',
            'notes' => 'Private detail metadata',
            'processing_status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE media_assets SET capture_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE media_asset_id = ?',
                [123.305278, 9.306944, $id],
            );
        }
    }
}
