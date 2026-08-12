<?php

namespace Tests\Feature\Sensor;

use App\Contracts\Media\PrivateObjectInspector;
use App\Exceptions\DownstreamServiceException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\FilesystemPrivateObjectInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensorDatasetUploadCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public int $calls = 0;

            public function inspect(string $disk, string $storageKey): array
            {
                $this->calls++;

                return ['size' => 12, 'checksum_sha256' => hash('sha256', 'sensor-bytes')];
            }
        });
    }

    public function test_it_finalizes_exact_safe_dataset_and_replays_idempotently(): void
    {
        Storage::fake('local');
        $this->app->bind(PrivateObjectInspector::class, FilesystemPrivateObjectInspector::class);
        $g = $this->graph();
        Storage::disk('local')->put($g['storage_key'], 'sensor-bytes');
        $checksum = hash('sha256', 'sensor-bytes');
        $first = $this->complete($g, 'complete-key', ['checksum_sha256' => strtoupper($checksum)])->assertCreated();
        $id = $first->json('data.sensor_dataset_id');
        $this->assertSame(['sensor_dataset_id', 'flight_session_id', 'sensor_id', 'dataset_type', 'file_name', 'file_format', 'recorded_start_at', 'recorded_end_at', 'spatial_reference', 'metadata', 'quality_status', 'created_at', 'updated_at'], array_keys($first->json('data')));
        $this->assertStringNotContainsString('storage_key', $first->getContent());
        $this->assertDatabaseHas('sensor_datasets', ['sensor_dataset_id' => $id, 'flight_session_id' => $g['flight'], 'quality_status' => 'pending']);
        $this->assertDatabaseHas('sensor_dataset_upload_sessions', ['upload_id' => $g['upload'], 'upload_status' => 'completed', 'sensor_dataset_id' => $id, 'checksum_sha256' => $checksum]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sensor_dataset.upload.complete', 'record_id' => $id]);
        $second = $this->complete($g, 'complete-key', ['checksum_sha256' => $checksum])->assertCreated();
        $second->assertJsonPath('data.sensor_dataset_id', $id);
        $this->assertDatabaseCount('sensor_datasets', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->complete($g, 'another-key', ['checksum_sha256' => $checksum])->assertConflict();

        $this->withToken($g['token'])->withHeader('Idempotency-Key', 'complete-key')
            ->postJson('/api/v1/sensor-datasets/uploads/'.$g['sibling_upload'].'/complete')
            ->assertConflict()->assertJsonPath('error.details.idempotency_key', 'complete-key');
    }

    public function test_it_verifies_size_checksum_expiry_and_tenant_boundaries(): void
    {
        $size = $this->graph(prefix: 'size-');
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $d, string $k): array
            {
                return ['size' => 99, 'checksum_sha256' => str_repeat('a', 64)];
            }
        });
        $this->complete($size, 'size')->assertConflict();
        $checksum = $this->graph(prefix: 'checksum-');
        $this->app['auth']->forgetGuards();
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $d, string $k): array
            {
                return ['size' => 12, 'checksum_sha256' => str_repeat('a', 64)];
            }
        });
        $this->complete($checksum, 'checksum', ['checksum_sha256' => str_repeat('b', 64)])->assertConflict();
        $expired = $this->graph(prefix: 'expired-');
        $this->app['auth']->forgetGuards();
        $createdAt = now('UTC')->subMinutes(2)->toIso8601String();
        DB::table('sensor_dataset_upload_sessions')->where('upload_id', $expired['upload'])->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'expires_at' => now('UTC')->subMinute()->toIso8601String(),
        ]);
        $this->complete($expired, 'expired')->assertConflict()->assertJsonPath('error.details.upload_status', 'expired');
        $g = $this->graph(prefix: 'boundary-');
        $this->app['auth']->forgetGuards();
        foreach ([$g['foreign_upload'], (string) Str::uuid(), 'bad-id'] as $id) {
            $this->withToken($g['token'])->withHeader('Idempotency-Key', 'id-'.$id)->postJson('/api/v1/sensor-datasets/uploads/'.$id.'/complete')->assertNotFound();
        }
    }

    public function test_it_validates_access_and_throttling(): void
    {
        $auth = $this->graph(prefix: 'auth-');
        $this->postJson('/api/v1/sensor-datasets/uploads/'.$auth['upload'].'/complete')->assertUnauthorized();
        $this->withToken($auth['token'])->postJson('/api/v1/sensor-datasets/uploads/'.$auth['upload'].'/complete')->assertBadRequest();
        $this->complete($auth, 'bad-checksum', ['checksum_sha256' => 'ABC'])->assertUnprocessable();
        $missing = $this->graph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->complete($missing, 'missing')->assertForbidden();
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->complete($inactive, 'inactive')->assertForbidden();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->graph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $this->complete($limited, 'one')->assertCreated();
        $this->complete($limited, 'two')->assertTooManyRequests();
    }

    public function test_it_versions_completion_fields_and_selected_column_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065900_add_sensor_upload_completion_fields.php'));
        $dcl = file_get_contents(database_path('sql/dcl/040_sensor_dataset_upload_completion_grants.sql'));
        $this->assertIsString($migration);
        foreach (['completion_idempotency_key', 'completion_fingerprint', 'sensor_dataset_id'] as $field) {
            $this->assertStringContainsString($field, $migration);
        }
        $this->assertStringContainsString('sensor_dataset_upload_completion_state_check', $migration);
        $this->assertStringContainsString('sensor_dataset_upload_completion_checksum_check', $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT UPDATE (completion_idempotency_key', $dcl);
        $this->assertStringContainsString('GRANT INSERT (sensor_dataset_id', $dcl);
        $this->assertStringContainsString('REVOKE SELECT ON TABLE app.sensor_datasets FROM mangroscan_api_rw;', $dcl);
        $this->assertStringNotContainsString('storage_key, file_format, recorded_start_at', $dcl);
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    public function test_it_preserves_state_when_verification_or_audit_persistence_fails(): void
    {
        $unavailable = $this->graph(prefix: 'unavailable-');
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $disk, string $storageKey): array
            {
                throw new DownstreamServiceException('Private object verification is unavailable.', 503, 'SERVICE_UNAVAILABLE');
            }
        });
        $this->complete($unavailable, 'unavailable')->assertServiceUnavailable();
        $this->assertDatabaseHas('sensor_dataset_upload_sessions', ['upload_id' => $unavailable['upload'], 'upload_status' => 'initiated']);
        $this->assertDatabaseCount('sensor_datasets', 0);

        $rollback = $this->graph(prefix: 'rollback-');
        $this->app['auth']->forgetGuards();
        $this->app->bind(PrivateObjectInspector::class, fn () => new class implements PrivateObjectInspector
        {
            public function inspect(string $disk, string $storageKey): array
            {
                return ['size' => 12, 'checksum_sha256' => hash('sha256', 'sensor-bytes')];
            }
        });
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, string $tableName, ?string $recordId, ?string $userId, ?array $oldValues, ?array $newValues, ?string $ipAddress, ?string $userAgent, ?string $requestId): AuditLog
            {
                throw new \RuntimeException('Audit persistence failed.');
            }
        });
        $this->complete($rollback, 'rollback')->assertInternalServerError();
        $this->assertDatabaseHas('sensor_dataset_upload_sessions', ['upload_id' => $rollback['upload'], 'upload_status' => 'initiated', 'sensor_dataset_id' => null]);
        $this->assertDatabaseCount('sensor_datasets', 0);
    }

    private function complete(array $g, string $key, array $body = [])
    {
        return $this->withToken($g['token'])->withHeader('Idempotency-Key', $key)->postJson('/api/v1/sensor-datasets/uploads/'.$g['upload'].'/complete', $body);
    }

    private function graph(string $prefix = '', bool $permission = true): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Complete Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'complete@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-complete@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'media.upload')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Completer', 'role_code' => $prefix.'completer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'media.upload', 'permission_name' => 'Upload media', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $local = $this->lineage($org, $actor, $prefix.'LOCAL');
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN');
        $upload = $this->uploadSession($local, $actor, $prefix);
        $siblingUpload = $this->uploadSession($local, $actor, $prefix.'sibling-');
        $foreignUpload = $this->uploadSession($foreign, $foreignActor, $prefix.'foreign-');

        return ['actor' => $actor, 'flight' => $local['flight'], 'upload' => $upload, 'sibling_upload' => $siblingUpload, 'foreign_upload' => $foreignUpload, 'storage_key' => 'sensor-complete/'.$upload.'.laz', 'token' => User::query()->findOrFail($actor)->createToken($prefix.'complete')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Dataset', 'last_name' => 'Completer', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        $sensor = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Complete sensor.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drone_sensors')->insert(['sensor_id' => $sensor, 'drone_id' => $drone, 'sensor_name' => 'LiDAR', 'sensor_type' => 'lidar', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return ['flight' => $flight, 'sensor' => $sensor];
    }

    private function uploadSession(array $lineage, string $actor, string $prefix): string
    {
        $id = (string) Str::uuid();
        $createdAt = now('UTC')->toIso8601String();
        $expiresAt = now('UTC')->addHour()->toIso8601String();
        DB::table('sensor_dataset_upload_sessions')->insert(['upload_id' => $id, 'flight_session_id' => $lineage['flight'], 'sensor_id' => $lineage['sensor'], 'initiated_by_user_id' => $actor, 'idempotency_key' => $prefix.'init', 'request_fingerprint' => str_repeat('a', 64), 'storage_disk' => 'local', 'storage_key' => 'sensor-complete/'.$id.'.laz', 'file_name' => 'survey.laz', 'dataset_type' => 'lidar_point_cloud', 'file_format' => 'LAZ', 'file_size_bytes' => 12, 'spatial_reference' => 'EPSG:4326', 'metadata' => json_encode(['points' => 12]), 'upload_status' => 'initiated', 'expires_at' => $expiresAt, 'created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $id;
    }
}
