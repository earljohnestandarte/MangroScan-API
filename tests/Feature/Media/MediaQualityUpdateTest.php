<?php

namespace Tests\Feature\Media;

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

class MediaQualityUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [MEDIA-06] Reviewers set QC metadata and receive the exact safe MediaAsset shape.
    public function test_it_sets_media_quality_with_transactional_audit_evidence(): void
    {
        $graph = $this->createGraph();

        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_06_success')
            ->patchJson('/api/v1/media/'.$graph['media_id'].'/quality', [
                'quality_score' => '88.5',
                'quality_status' => ' NEEDS_RECAPTURE ',
                'notes' => ' Motion blur near the canopy. ',
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req_media_06_success')
            ->assertJsonPath('meta.request_id', 'req_media_06_success')
            ->assertJsonPath('data.media_asset_id', $graph['media_id'])
            ->assertJsonPath('data.quality_score', '88.50')
            ->assertJsonPath('data.quality_status', 'needs_recapture')
            ->assertJsonPath('data.notes', 'Motion blur near the canopy.')
            ->assertJsonPath('data.processing_status', 'pending');

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

        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'quality_score' => '88.50',
            'quality_status' => 'needs_recapture',
            'notes' => 'Motion blur near the canopy.',
            'sync_version' => 2,
        ]);

        $audit = AuditLog::query()->sole();
        $this->assertSame('media.quality', $audit->action);
        $this->assertSame('media_assets', $audit->table_name);
        $this->assertSame($graph['media_id'], $audit->record_id);
        $this->assertSame($graph['actor_id'], $audit->user_id);
        $this->assertSame('req_media_06_success', $audit->request_id);
        $this->assertSame('94.50', $audit->old_values['quality_score']);
        $this->assertSame('acceptable', $audit->old_values['quality_status']);
        $this->assertSame(1, $audit->old_values['sync_version']);
        $this->assertSame('88.50', $audit->new_values['quality_score']);
        $this->assertSame('needs_recapture', $audit->new_values['quality_status']);
        $this->assertSame(2, $audit->new_values['sync_version']);
        $this->assertSame('private/'.$graph['media_id'].'/quality.jpg', $audit->new_values['storage_key']);
        $this->assertSame(str_repeat('a', 64), $audit->new_values['checksum_sha256']);
    }

    // [MEDIA-06] Optional fields distinguish omission from an explicit null.
    public function test_it_preserves_omitted_fields_and_clears_explicit_nulls(): void
    {
        $graph = $this->createGraph();
        $uri = '/api/v1/media/'.$graph['media_id'].'/quality';

        $this->withToken($graph['token'])
            ->patchJson($uri, ['quality_status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.quality_score', '94.50')
            ->assertJsonPath('data.notes', 'Initial review');

        $this->withToken($graph['token'])
            ->patchJson($uri, [
                'quality_status' => 'pending',
                'quality_score' => null,
                'notes' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.quality_score', null)
            ->assertJsonPath('data.notes', null);

        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'quality_score' => null,
            'quality_status' => 'pending',
            'notes' => null,
            'sync_version' => 3,
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    // [MEDIA-06] The documented QC fields and physical numeric domain are validated.
    public function test_it_validates_quality_input(): void
    {
        $graph = $this->createGraph();
        $uri = '/api/v1/media/'.$graph['media_id'].'/quality';

        $this->withToken($graph['token'])
            ->patchJson($uri, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quality_status'], 'error.details');

        $this->withToken($graph['token'])
            ->patchJson($uri, [
                'quality_status' => 'approved',
                'quality_score' => -0.01,
                'notes' => ['invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quality_status', 'quality_score', 'notes'], 'error.details');

        $this->withToken($graph['token'])
            ->patchJson($uri, ['quality_status' => 'acceptable', 'quality_score' => 100.001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quality_score'], 'error.details');

        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'quality_score' => '94.50',
            'quality_status' => 'acceptable',
            'sync_version' => 1,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MEDIA-06] Foreign, deleted, missing, malformed and deleted-parent media stay non-enumerable.
    public function test_it_hides_unavailable_media(): void
    {
        $graph = $this->createGraph();
        $payload = ['quality_status' => 'rejected'];

        foreach ([$graph['foreign_media_id'], $graph['deleted_media_id'], (string) Str::uuid(), 'not-a-uuid'] as $mediaId) {
            $this->withToken($graph['token'])
                ->patchJson('/api/v1/media/'.$mediaId.'/quality', $payload)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $deletedParent = $this->createGraph('deleted-parent-');
        DB::table('survey_sites')->where('site_id', $deletedParent['site_id'])->update(['deleted_at' => now()]);
        $this->withToken($deletedParent['token'])
            ->patchJson('/api/v1/media/'.$deletedParent['media_id'].'/quality', $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MEDIA-06] Active authentication and tenant-valid media.quality_review are mandatory.
    public function test_it_enforces_access(): void
    {
        $missingPermission = $this->createGraph('missing-', localPermission: false);
        $uri = '/api/v1/media/'.$missingPermission['media_id'].'/quality';

        $this->patchJson($uri, ['quality_status' => 'rejected'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->withToken($missingPermission['token'])
            ->patchJson($uri, ['quality_status' => 'rejected'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.quality_review');

        $this->app['auth']->forgetGuards();
        $foreignGrant = $this->createGraph('foreign-grant-', localPermission: false, foreignPermission: true);
        $this->withToken($foreignGrant['token'])
            ->patchJson('/api/v1/media/'.$foreignGrant['media_id'].'/quality', ['quality_status' => 'rejected'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'media.quality_review');

        $this->app['auth']->forgetGuards();
        $inactive = $this->createGraph('inactive-');
        DB::table('users')->where('user_id', $inactive['actor_id'])->update(['status' => 'inactive']);
        $this->withToken($inactive['token'])
            ->patchJson('/api/v1/media/'.$inactive['media_id'].'/quality', ['quality_status' => 'rejected'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [MEDIA-06] Audit failure rolls the quality mutation and sync version back together.
    public function test_it_rolls_back_when_audit_persistence_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->patchJson('/api/v1/media/'.$graph['media_id'].'/quality', [
                'quality_score' => 10,
                'quality_status' => 'rejected',
                'notes' => 'Should roll back',
            ])
            ->assertInternalServerError();

        $this->assertDatabaseHas('media_assets', [
            'media_asset_id' => $graph['media_id'],
            'quality_score' => '94.50',
            'quality_status' => 'acceptable',
            'notes' => 'Initial review',
            'sync_version' => 1,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MEDIA-06] QC updates share the authenticated budget and use a selected-column grant.
    public function test_it_rate_limits_and_has_least_privilege_dcl(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/media/'.$graph['media_id'].'/quality';

        $this->withToken($graph['token'])
            ->patchJson($uri, ['quality_status' => 'acceptable'])
            ->assertOk();
        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_06_throttled')
            ->patchJson($uri, ['quality_status' => 'rejected'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.request_id', 'req_media_06_throttled');

        $dcl = file_get_contents(database_path('sql/dcl/044_media_quality_review_grants.sql'));
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        foreach (['quality_score', 'quality_status', 'notes', 'sync_version', 'updated_at'] as $column) {
            $this->assertStringContainsString($column, $dcl);
        }
        foreach (['storage_key', 'processing_status', 'DELETE', 'mangroscan_worker', 'mangroscan_report_ro'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /**
     * @return array{
     *   actor_id: string,
     *   site_id: string,
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
        $permissionId = DB::table('permissions')->where('permission_code', 'media.quality_review')->value('permission_id');

        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Media Quality Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Media Quality Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'media-quality@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-media-quality@example.test');

        DB::table('roles')->insert([
            ['role_id' => $roleId, 'organization_id' => $organizationId, 'role_name' => $prefix.'Media Quality Reviewer', 'role_code' => $prefix.'media_quality_reviewer', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRoleId, 'organization_id' => $foreignOrganizationId, 'role_name' => $prefix.'Foreign Media Quality Reviewer', 'role_code' => $prefix.'foreign_media_quality_reviewer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        if (! is_string($permissionId)) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'permission_id' => $permissionId,
                'permission_code' => 'media.quality_review',
                'permission_name' => 'Review media quality',
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
        $this->insertSite($siteId, $organizationId, $actorId, $prefix.'MEDIA-QUALITY-SITE');
        $this->insertSite($foreignSiteId, $foreignOrganizationId, $foreignUserId, $prefix.'FOREIGN-MEDIA-QUALITY-SITE');

        $missionId = (string) Str::uuid();
        $foreignMissionId = (string) Str::uuid();
        $this->insertMission($missionId, $siteId, $actorId, $prefix.'MEDIA-QUALITY-MISSION');
        $this->insertMission($foreignMissionId, $foreignSiteId, $foreignUserId, $prefix.'FOREIGN-MEDIA-QUALITY-MISSION');

        $droneId = (string) Str::uuid();
        $foreignDroneId = (string) Str::uuid();
        $this->insertDrone($droneId, $organizationId, $prefix.'MEDIA-QUALITY-DRONE');
        $this->insertDrone($foreignDroneId, $foreignOrganizationId, $prefix.'FOREIGN-MEDIA-QUALITY-DRONE');

        $flightId = (string) Str::uuid();
        $foreignFlightId = (string) Str::uuid();
        $this->insertFlight($flightId, $missionId, $droneId, $actorId, $prefix.'MEDIA-QUALITY-FLIGHT');
        $this->insertFlight($foreignFlightId, $foreignMissionId, $foreignDroneId, $foreignUserId, $prefix.'FOREIGN-MEDIA-QUALITY-FLIGHT');

        $mediaId = (string) Str::uuid();
        $foreignMediaId = (string) Str::uuid();
        $deletedMediaId = (string) Str::uuid();
        $this->insertMedia($mediaId, $flightId, $actorId, 'quality.jpg');
        $this->insertMedia($foreignMediaId, $foreignFlightId, $foreignUserId, 'foreign-quality.jpg');
        $this->insertMedia($deletedMediaId, $flightId, $actorId, 'deleted-quality.jpg', deleted: true);

        return [
            'actor_id' => $actorId,
            'site_id' => $siteId,
            'media_id' => $mediaId,
            'foreign_media_id' => $foreignMediaId,
            'deleted_media_id' => $deletedMediaId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'media-quality')->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Media',
            'last_name' => 'Reviewer',
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
            'mission_objective' => 'Review captured media quality.',
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
            'captured_at' => '2026-08-12T02:50:00+00:00',
            'metadata' => json_encode(['camera' => 'wide'], JSON_THROW_ON_ERROR),
            'quality_score' => '94.50',
            'quality_status' => 'acceptable',
            'notes' => 'Initial review',
            'processing_status' => 'pending',
            'sync_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
