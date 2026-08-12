<?php

namespace Tests\Feature\Media;

use App\Contracts\Media\PrivateObjectInspector;
use App\Exceptions\DownstreamServiceException;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaUploadCompleteTest extends TestCase
{
    use RefreshDatabase;

    // [MEDIA-03] A verified object atomically becomes one safe MediaAsset.
    public function test_it_finalizes_a_verified_private_object(): void
    {
        Storage::fake('local');
        $bytes = 'verified-media-bytes';
        $graph = $this->createGraph(size: strlen($bytes), checksum: hash('sha256', $bytes));
        Storage::disk('local')->put($graph['storage_key'], $bytes);
        $response = $this->withToken($graph['token'])
            ->withHeader('Idempotency-Key', 'complete-001')
            ->withHeader('X-Request-ID', 'req_media_03')
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', [
                'checksum_sha256' => strtoupper(hash('sha256', $bytes)),
            ]);

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_media_03')
            ->assertJsonPath('meta.request_id', 'req_media_03')
            ->assertJsonPath('data.flight_session_id', $graph['flight_id'])
            ->assertJsonPath('data.uploaded_by_user_id', $graph['actor_id'])
            ->assertJsonPath('data.file_name', 'DJI_0041.JPG')
            ->assertJsonPath('data.file_size_bytes', strlen($bytes))
            ->assertJsonPath('data.checksum_sha256', hash('sha256', $bytes))
            ->assertJsonPath('data.capture_location.type', 'Point')
            ->assertJsonPath('data.capture_location.coordinates.0', 123.305278)
            ->assertJsonPath('data.metadata.camera', 'wide')
            ->assertJsonPath('data.quality_status', 'pending')
            ->assertJsonPath('data.processing_status', 'pending');
        $this->assertSame([
            'media_asset_id', 'flight_session_id', 'uploaded_by_user_id', 'file_name',
            'file_type', 'mime_type', 'file_size_bytes', 'checksum_sha256',
            'capture_location', 'captured_at', 'metadata', 'quality_score',
            'quality_status', 'notes', 'processing_status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertArrayNotHasKey('storage_key', $response->json('data'));
        $this->assertDatabaseHas('media_upload_sessions', [
            'upload_id' => $graph['upload_id'], 'upload_status' => 'completed',
            'completion_idempotency_key' => 'complete-001',
            'media_asset_id' => $response->json('data.media_asset_id'),
        ]);
        $this->assertDatabaseCount('media_assets', 1);
        $audit = DB::table('audit_logs')->where('action', 'media.upload.complete')->first();
        $this->assertNotNull($audit);
        $this->assertSame('req_media_03', $audit->request_id);
    }

    // [MEDIA-03] Identical completion retries return the same asset without reinspection.
    public function test_it_idempotently_returns_the_completed_asset(): void
    {
        $calls = new class implements PrivateObjectInspector
        {
            public int $count = 0;

            public function inspect(string $disk, string $storageKey): array
            {
                $this->count++;

                return ['size' => 5, 'checksum_sha256' => hash('sha256', '12345')];
            }
        };
        $this->app->instance(PrivateObjectInspector::class, $calls);
        $graph = $this->createGraph(size: 5, checksum: hash('sha256', '12345'));
        $headers = ['Idempotency-Key' => 'same-completion'];
        $payload = ['parts' => [['part_number' => 2, 'etag' => 'b'], ['part_number' => 1, 'etag' => 'a']]];
        $first = $this->withToken($graph['token'])->withHeaders($headers)->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', $payload);
        $second = $this->withToken($graph['token'])->withHeaders($headers)->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', [
            'parts' => array_reverse($payload['parts']),
        ]);

        $first->assertCreated();
        $second->assertCreated()->assertJsonPath('data.media_asset_id', $first->json('data.media_asset_id'));
        $this->assertSame(1, $calls->count);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'different-completion')
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', $payload)
            ->assertConflict()->assertJsonPath('error.details.upload_status', 'completed');

        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'same-completion')
            ->postJson('/api/v1/media/uploads/'.$graph['sibling_upload_id'].'/complete')
            ->assertConflict()->assertJsonPath('error.details.idempotency_key', 'same-completion');
    }

    // [MEDIA-03] Missing objects, wrong size, and wrong checksum never create an asset.
    public function test_it_rejects_unverified_objects(): void
    {
        Storage::fake('local');
        $missing = $this->createGraph(prefix: 'missing-');
        $this->complete($missing, 'missing-object')->assertConflict()
            ->assertJsonPath('error.details.object_present', false);

        $wrongSize = $this->createGraph(size: 99, prefix: 'size-');
        $this->app['auth']->forgetGuards();
        Storage::disk('local')->put($wrongSize['storage_key'], 'short');
        $this->complete($wrongSize, 'wrong-size')->assertConflict()
            ->assertJsonPath('error.details.expected_size_bytes', 99)
            ->assertJsonPath('error.details.actual_size_bytes', 5);

        $wrongChecksum = $this->createGraph(size: 5, checksum: str_repeat('a', 64), prefix: 'checksum-');
        $this->app['auth']->forgetGuards();
        Storage::disk('local')->put($wrongChecksum['storage_key'], '12345');
        $this->complete($wrongChecksum, 'wrong-checksum')->assertConflict()
            ->assertJsonPath('error.details.checksum_matches_object', false);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [MEDIA-03] Completion checksum cannot contradict initiation evidence.
    public function test_it_rejects_a_changed_declared_checksum_before_finalization(): void
    {
        $graph = $this->createGraph(size: 5, checksum: str_repeat('a', 64));
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $disk, string $storageKey): array
            {
                return ['size' => 5, 'checksum_sha256' => str_repeat('b', 64)];
            }
        });

        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'changed-checksum')
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', ['checksum_sha256' => str_repeat('b', 64)])
            ->assertConflict()->assertJsonPath('error.details.checksum_matches_initiation', false);
        $this->assertDatabaseCount('media_assets', 0);
    }

    // [MEDIA-03] Expired, foreign, missing and malformed sessions remain unavailable.
    public function test_it_enforces_session_lifecycle_and_tenant_boundaries(): void
    {
        $graph = $this->createGraph(expiresAt: new DateTimeImmutable('-1 minute'));
        $this->complete($graph, 'expired')->assertConflict()->assertJsonPath('error.details.upload_status', 'expired');

        foreach ([$graph['foreign_upload_id'], (string) Str::uuid(), 'not-a-uuid'] as $uploadId) {
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'id-'.$uploadId)
                ->postJson('/api/v1/media/uploads/'.$uploadId.'/complete')
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    // [MEDIA-03] Request structure and idempotency header are validated.
    public function test_it_validates_completion_input(): void
    {
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'invalid-parts')
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete', [
                'checksum_sha256' => 'ABC', 'parts' => [['part_number' => 0, 'etag' => '']],
            ])->assertUnprocessable()->assertJsonValidationErrors([
                'checksum_sha256', 'parts.0.part_number', 'parts.0.etag',
            ], 'error.details');
        $this->withoutHeader('Idempotency-Key')->withToken($graph['token'])
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete')
            ->assertBadRequest()->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    // [MEDIA-03] Authentication, tenant-valid permission, and active identity are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->withHeader('Idempotency-Key', 'unauth')->postJson('/api/v1/media/uploads/'.$auth['upload_id'].'/complete')->assertUnauthorized();
        $missing = $this->createGraph(permission: false, prefix: 'missing-perm-');
        $this->complete($missing, 'missing-perm')->assertForbidden()->assertJsonPath('error.details.required_permission', 'media.upload');
        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->complete($foreign, 'foreign-role')->assertForbidden()->assertJsonPath('error.details.required_permission', 'media.upload');
    }

    // [MEDIA-03] Inactive identities cannot finalize uploads.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);
        $this->complete($graph, 'inactive')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [MEDIA-03] Storage verification outages return 503 without state changes.
    public function test_it_handles_storage_verification_failure(): void
    {
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $disk, string $storageKey): array
            {
                throw new DownstreamServiceException('Private object verification is unavailable.', 503, 'SERVICE_UNAVAILABLE');
            }
        });
        $graph = $this->createGraph();
        $this->complete($graph, 'storage-down')->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->assertDatabaseHas('media_upload_sessions', ['upload_id' => $graph['upload_id'], 'upload_status' => 'initiated']);
        $this->assertDatabaseCount('media_assets', 0);
    }

    // [MEDIA-03] Completion uses the shared authenticated request budget.
    public function test_it_rate_limits_completion(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $disk, string $storageKey): array
            {
                return ['size' => 5, 'checksum_sha256' => hash('sha256', '12345')];
            }
        });
        $graph = $this->createGraph(size: 5, checksum: hash('sha256', '12345'));
        $this->complete($graph, 'first')->assertCreated();
        $this->complete($graph, 'first')->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [MEDIA-03] Completion adds only session updates and MediaAsset inserts.
    public function test_it_versions_completion_idempotency_and_narrow_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065100_add_completion_idempotency_to_media_upload_sessions.php'));
        $dcl = file_get_contents(database_path('sql/dcl/032_media_upload_completion_grants.sql'));
        $this->assertIsString($migration);
        foreach (['completion_idempotency_key', 'completion_fingerprint', "->unique(['initiated_by_user_id', 'completion_idempotency_key'])"] as $column) {
            $this->assertStringContainsString($column, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT UPDATE (', $dcl);
        $this->assertStringContainsString('GRANT INSERT (', $dcl);
        $this->assertStringContainsString('storage_key,', $dcl);
        $this->assertStringContainsString(') ON TABLE app.media_assets TO mangroscan_api_rw;', $dcl);
        foreach (['DELETE', 'mangroscan_report_ro', 'mangroscan_worker', 'sync_version', 'deleted_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    private function complete(array $graph, string $key)
    {
        return $this->withToken($graph['token'])->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/media/uploads/'.$graph['upload_id'].'/complete');
    }

    /** @return array<string, string> */
    private function createGraph(
        int $size = 20,
        ?string $checksum = null,
        ?DateTimeImmutable $expiresAt = null,
        bool $permission = true,
        bool $foreignPermission = false,
        string $prefix = '',
    ): array {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'complete@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-complete@example.test');
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Completer', 'role_code' => $prefix.'completer', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => $prefix.'Foreign Completer', 'role_code' => $prefix.'foreign_completer', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'media.upload')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'media.upload', 'permission_name' => 'Upload media', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $assigned = $foreignPermission ? $foreignRole : $role;
            DB::table('role_permissions')->insert(['role_id' => $assigned, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $assigned, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignUser, $prefix.'FOREIGN');
        $upload = (string) Str::uuid();
        $siblingUpload = (string) Str::uuid();
        $foreignUpload = (string) Str::uuid();
        $storage = 'missions/'.$local['mission'].'/flights/'.$local['flight'].'/media/'.$upload.'.jpg';
        $siblingStorage = 'missions/'.$local['mission'].'/flights/'.$local['flight'].'/media/'.$siblingUpload.'.jpg';
        $this->upload($upload, $local['flight'], $actor, $storage, $size, $checksum, $expiresAt ?? new DateTimeImmutable('+30 minutes'));
        $this->upload($siblingUpload, $local['flight'], $actor, $siblingStorage, $size, $checksum, new DateTimeImmutable('+30 minutes'));
        $this->upload($foreignUpload, $foreign['flight'], $foreignUser, 'foreign/'.$foreignUpload.'.jpg', $size, $checksum, new DateTimeImmutable('+30 minutes'));

        return ['actor_id' => $actor, 'flight_id' => $local['flight'], 'upload_id' => $upload, 'sibling_upload_id' => $siblingUpload, 'foreign_upload_id' => $foreignUpload, 'storage_key' => $storage, 'token' => User::findOrFail($actor)->createToken($prefix.'complete')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Media', 'last_name' => 'Complete', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{mission:string,flight:string} */
    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Finalize evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return ['mission' => $mission, 'flight' => $flight];
    }

    private function upload(string $id, string $flight, string $actor, string $storage, int $size, ?string $checksum, DateTimeImmutable $expires): void
    {
        $createdAt = $expires->modify('-30 minutes')->format(DATE_ATOM);
        DB::table('media_upload_sessions')->insert(['upload_id' => $id, 'flight_session_id' => $flight, 'initiated_by_user_id' => $actor, 'idempotency_key' => 'init-'.$id, 'request_fingerprint' => hash('sha256', $id), 'storage_disk' => 'local', 'storage_key' => $storage, 'file_name' => 'DJI_0041.JPG', 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_bytes' => $size, 'checksum_sha256' => $checksum, 'capture_location' => DB::getDriverName() === 'pgsql' ? null : json_encode(['type' => 'Point', 'coordinates' => [123.305278, 9.306944]], JSON_THROW_ON_ERROR), 'captured_at' => '2026-08-10T02:50:00+00:00', 'metadata' => json_encode(['camera' => 'wide'], JSON_THROW_ON_ERROR), 'upload_status' => 'initiated', 'expires_at' => $expires->format(DATE_ATOM), 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE media_upload_sessions SET capture_location=ST_SetSRID(ST_MakePoint(?,?),4326) WHERE upload_id=?', [123.305278, 9.306944, $id]);
        }
    }
}
