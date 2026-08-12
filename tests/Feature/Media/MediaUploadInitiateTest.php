<?php

namespace Tests\Feature\Media;

use App\Contracts\Media\PrivateUploadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaUploadInitiateTest extends TestCase
{
    use RefreshDatabase;

    // [MEDIA-02] Initiation persists safe metadata and returns a real signed private PUT target.
    public function test_it_initiates_a_private_media_upload(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('Idempotency-Key', 'media-upload-001')
            ->withHeader('X-Request-ID', 'req_media_02')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload());

        $response->assertCreated()->assertHeader('X-Request-ID', 'req_media_02')
            ->assertJsonPath('meta.request_id', 'req_media_02');
        $this->assertSame(['upload_id', 'storage_key', 'upload_url'], array_keys($response->json('data')));
        $this->assertMatchesRegularExpression('/^missions\/[0-9a-f-]+\/flights\/[0-9a-f-]+\/media\/[0-9a-f-]+\.jpg$/', $response->json('data.storage_key'));
        $this->assertStringNotContainsString('DJI_0041', $response->json('data.storage_key'));
        $this->assertStringContainsString('/storage/missions/', $response->json('data.upload_url'));
        $this->assertStringContainsString('signature=', $response->json('data.upload_url'));
        $this->assertStringContainsString('upload=1', $response->json('data.upload_url'));

        $this->assertDatabaseHas('media_upload_sessions', [
            'upload_id' => $response->json('data.upload_id'),
            'flight_session_id' => $graph['flight_id'],
            'initiated_by_user_id' => $graph['actor_id'],
            'idempotency_key' => 'media-upload-001',
            'file_name' => 'DJI_0041.JPG',
            'file_type' => 'image',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 482003114,
            'checksum_sha256' => str_repeat('a', 64),
            'upload_status' => 'initiated',
        ]);
        $this->assertDatabaseCount('media_assets', 0);
        $audit = DB::table('audit_logs')->where('action', 'media.upload.initiate')->first();
        $this->assertNotNull($audit);
        $this->assertSame('req_media_02', $audit->request_id);
        $this->assertStringNotContainsString('upload_url', (string) $audit->new_values);
    }

    // [MEDIA-02] A valid signed target accepts one private file without creating media yet.
    public function test_the_local_signed_upload_target_accepts_the_file(): void
    {
        config(['mangroscan.media.disk' => 'local']);
        $graph = $this->createGraph();
        $initiation = $this->withToken($graph['token'])
            ->withHeader('Idempotency-Key', 'put-target')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload());
        $initiation->assertCreated();

        $target = $initiation->json('data.upload_url');
        $storageKey = $initiation->json('data.storage_key');
        try {
            $this->call('PUT', $target, content: 'private-image-bytes')->assertNoContent();
            $this->assertTrue(Storage::disk('local')->exists($storageKey));
            $this->assertSame('private-image-bytes', Storage::disk('local')->get($storageKey));
            $this->assertDatabaseCount('media_assets', 0);
        } finally {
            Storage::disk('local')->delete($storageKey);
        }
    }

    // [MEDIA-02] Identical retries reuse the session while changed payloads conflict.
    public function test_it_is_idempotent_per_user_and_rejects_key_reuse(): void
    {
        $this->app->bind(PrivateUploadUrlIssuer::class, fn () => new class implements PrivateUploadUrlIssuer
        {
            public int $calls = 0;

            public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array
            {
                $this->calls++;

                return ['url' => 'https://uploads.example.test/'.$storageKey.'?call='.$this->calls, 'headers' => ['X-Test' => '1']];
            }
        });
        $graph = $this->createGraph();
        $headers = ['Idempotency-Key' => 'same-key'];
        $first = $this->withToken($graph['token'])->withHeaders($headers)
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload());
        $second = $this->withToken($graph['token'])->withHeaders($headers)
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload());

        $first->assertCreated();
        $second->assertCreated()->assertJsonPath('data.upload_id', $first->json('data.upload_id'))
            ->assertJsonPath('data.storage_key', $first->json('data.storage_key'));
        $this->assertDatabaseCount('media_upload_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);

        $changed = $this->payload();
        $changed['file_size_bytes']++;
        $this->withToken($graph['token'])->withHeaders($headers)
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $changed)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.details.idempotency_key', 'same-key');
    }

    // [MEDIA-02] The request validates file, checksum, geometry, time, size, and idempotency headers.
    public function test_it_validates_the_upload_contract(): void
    {
        $graph = $this->createGraph();
        $payload = [
            'file_name' => '../unsafe.exe', 'file_type' => 'video', 'mime_type' => 'image/jpeg',
            'file_size_bytes' => 0, 'checksum_sha256' => 'ABC',
            'capture_location' => ['type' => 'Polygon', 'coordinates' => [181, 91]],
            'captured_at' => 'not-a-date', 'metadata' => 'not-an-object',
        ];
        $this->withToken($graph['token'])->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors([
                'file_name', 'mime_type', 'file_size_bytes', 'checksum_sha256',
                'capture_location.type', 'capture_location.coordinates.0',
                'capture_location.coordinates.1', 'captured_at', 'metadata',
            ], 'error.details');

        $this->withToken($graph['token'])->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())
            ->assertBadRequest()->assertJsonPath('error.code', 'BAD_REQUEST');
        $this->assertDatabaseCount('media_upload_sessions', 0);
    }

    // [MEDIA-02] Foreign flights and invalid workflow states never produce upload targets.
    public function test_it_enforces_tenant_and_flight_workflow_boundaries(): void
    {
        $graph = $this->createGraph(status: 'planned');
        foreach ([$graph['foreign_flight_id'], (string) Str::uuid(), 'not-a-uuid'] as $id) {
            $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'id-'.$id)
                ->postJson('/api/v1/flights/'.$id.'/media/uploads', $this->payload())
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'planned-flight')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())
            ->assertConflict()->assertJsonPath('error.details.current_status', 'planned');
        $this->assertDatabaseCount('media_upload_sessions', 0);
    }

    // [MEDIA-02] Authentication, tenant-valid permission, and active identity are mandatory.
    public function test_it_enforces_authentication_and_permission_scope(): void
    {
        $auth = $this->createGraph(prefix: 'auth-');
        $this->withHeader('Idempotency-Key', 'unauth')->postJson('/api/v1/flights/'.$auth['flight_id'].'/media/uploads', $this->payload())->assertUnauthorized();
        $missing = $this->createGraph(permission: false, prefix: 'missing-');
        $this->withToken($missing['token'])->withHeader('Idempotency-Key', 'missing')
            ->postJson('/api/v1/flights/'.$missing['flight_id'].'/media/uploads', $this->payload())
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'media.upload');
        $foreign = $this->createGraph(foreignPermission: true, prefix: 'foreign-role-');
        $this->withToken($foreign['token'])->withHeader('Idempotency-Key', 'foreign')
            ->postJson('/api/v1/flights/'.$foreign['flight_id'].'/media/uploads', $this->payload())
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'media.upload');
    }

    // [MEDIA-02] Inactive identities cannot initiate an upload.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'inactive')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('media_upload_sessions', 0);
    }

    // [MEDIA-02] Unsupported private transport returns 503 and no durable session.
    public function test_it_preserves_a_retryable_session_when_private_transport_is_unavailable(): void
    {
        $this->app->bind(PrivateUploadUrlIssuer::class, fn () => new class implements PrivateUploadUrlIssuer
        {
            public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array
            {
                throw new DownstreamServiceException('Private upload transport is unavailable.', 503, 'SERVICE_UNAVAILABLE');
            }
        });
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'no-storage')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->assertDatabaseCount('media_upload_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // [MEDIA-02] Initiation uses the shared authenticated request budget.
    public function test_it_rate_limits_upload_initiation(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'first')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())->assertCreated();
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'second')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())
            ->assertTooManyRequests()->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('media_upload_sessions', 1);
    }

    // [MEDIA-02] PostgreSQL enforces upload domains and API privileges are initiation-only.
    public function test_it_versions_upload_schema_and_narrow_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065000_create_media_upload_sessions_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/031_media_upload_initiation_grants.sql'));
        $this->assertIsString($migration);
        foreach (['media_upload_sessions_file_type_check', 'media_upload_sessions_status_check', 'media_upload_sessions_checksum_check', "geometry('capture_location', 'point', 4326)", "->unique(['initiated_by_user_id', 'idempotency_key'])"] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT ON TABLE app.media_upload_sessions TO mangroscan_api_rw;', $dcl);
        foreach (['UPDATE', 'DELETE', 'mangroscan_report_ro', 'mangroscan_worker'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL upload-session verification.');
        }
        $graph = $this->createGraph(prefix: 'constraint-');
        $this->withToken($graph['token'])->withHeader('Idempotency-Key', 'constraint')
            ->postJson('/api/v1/flights/'.$graph['flight_id'].'/media/uploads', $this->payload())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('media_upload_sessions')->update(['upload_status' => 'uploaded']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'file_name' => ' DJI_0041.JPG ', 'file_type' => ' IMAGE ', 'mime_type' => ' IMAGE/JPEG ',
            'file_size_bytes' => 482003114, 'checksum_sha256' => str_repeat('A', 64),
            'capture_location' => ['type' => 'Point', 'coordinates' => [123.305278, 9.306944]],
            'captured_at' => '2026-08-10T02:50:00+00:00', 'metadata' => ['camera' => 'wide'],
        ];
    }

    /** @return array<string, string> */
    private function createGraph(
        string $status = 'completed', bool $permission = true,
        bool $foreignPermission = false, string $prefix = '',
    ): array {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignUser = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => $prefix.'Upload Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Upload Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, $prefix.'upload@example.test');
        $this->user($foreignUser, $foreignOrg, $prefix.'foreign-upload@example.test');
        $role = (string) Str::uuid();
        $foreignRole = (string) Str::uuid();
        DB::table('roles')->insert([
            ['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Uploader', 'role_code' => $prefix.'uploader', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $foreignRole, 'organization_id' => $foreignOrg, 'role_name' => $prefix.'Foreign Uploader', 'role_code' => $prefix.'foreign_uploader', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $permissionId = DB::table('permissions')->where('permission_code', 'media.upload')->value('permission_id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'media.upload', 'permission_name' => 'Upload media', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission || $foreignPermission) {
            $assigned = $foreignPermission ? $foreignRole : $role;
            DB::table('role_permissions')->insert(['role_id' => $assigned, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $assigned, 'created_at' => now(), 'updated_at' => now()]);
        }
        $flight = $this->flight($org, $actor, $status, $prefix.'LOCAL');
        $foreignFlight = $this->flight($foreignOrg, $foreignUser, 'completed', $prefix.'FOREIGN');

        return ['actor_id' => $actor, 'flight_id' => $flight, 'foreign_flight_id' => $foreignFlight, 'token' => User::findOrFail($actor)->createToken($prefix.'upload')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Media', 'last_name' => 'Uploader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function flight(string $org, string $actor, string $status, string $code): string
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Upload evidence.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => $status, 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);

        return $flight;
    }
}
