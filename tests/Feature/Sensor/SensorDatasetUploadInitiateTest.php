<?php

namespace Tests\Feature\Sensor;

use App\Contracts\Media\PrivateUploadUrlIssuer;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensorDatasetUploadInitiateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PrivateUploadUrlIssuer::class, fn () => new class implements PrivateUploadUrlIssuer
        {
            public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array
            {
                return ['url' => 'https://uploads.example.test/'.$storageKey, 'headers' => []];
            }
        });
    }

    public function test_it_initiates_exact_private_sensor_upload_and_is_idempotent(): void
    {
        $g = $this->graph();
        $first = $this->postUpload($g, 'sensor-key')->assertCreated();
        $id = $first->json('data.upload_id');
        $this->assertSame(['upload_id', 'storage_key', 'upload_url'], array_keys($first->json('data')));
        $this->assertStringStartsWith('https://uploads.example.test/missions/', $first->json('data.upload_url'));
        $this->assertStringContainsString('/sensor-datasets/', $first->json('data.storage_key'));
        $this->assertDatabaseHas('sensor_dataset_upload_sessions', ['upload_id' => $id, 'sensor_id' => $g['sensor'], 'dataset_type' => 'lidar_point_cloud', 'file_format' => 'LAZ', 'file_size_bytes' => 4096, 'upload_status' => 'initiated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sensor_dataset.upload.initiate', 'record_id' => $id]);
        $second = $this->postUpload($g, 'sensor-key')->assertCreated();
        $second->assertJsonPath('data.upload_id', $id);
        $this->assertDatabaseCount('sensor_dataset_upload_sessions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $changed = $this->payload();
        $changed['file_size_bytes'] = 4097;
        $this->postUpload($g, 'sensor-key', $changed)->assertConflict();
    }

    public function test_it_validates_sensor_flight_workflow_and_access_boundaries(): void
    {
        $g = $this->graph(status: 'planned');
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/sensor-datasets/uploads', $this->payload())->assertBadRequest();
        $bad = $this->payload();
        $bad['file_name'] = '../bad';
        $bad['dataset_type'] = 'image';
        $bad['sensor_id'] = 'nope';
        $bad['file_size_bytes'] = 0;
        $bad['metadata'] = 'x';
        $this->postUpload($g, 'bad', $bad)->assertUnprocessable();
        $this->postUpload($g, 'planned')->assertConflict();
        foreach ([$g['foreign_flight'], (string) Str::uuid(), 'bad-id'] as $id) {
            $this->withToken($g['token'])->withHeader('Idempotency-Key', 'flight-'.$id)->postJson('/api/v1/flights/'.$id.'/sensor-datasets/uploads', $this->payload())->assertNotFound();
        }
        DB::table('flight_sessions')->where('flight_session_id', $g['flight'])->update(['flight_status' => 'flying']);
        $foreignSensor = $this->payload();
        $foreignSensor['sensor_id'] = $g['foreign_sensor'];
        $this->postUpload($g, 'foreign-sensor', $foreignSensor)->assertNotFound();
    }

    public function test_it_enforces_auth_permission_identity_and_throttling(): void
    {
        $auth = $this->graph(prefix: 'auth-');
        $this->withHeader('Idempotency-Key', 'auth')->postJson('/api/v1/flights/'.$auth['flight'].'/sensor-datasets/uploads', $this->payload())->assertUnauthorized();
        $missing = $this->graph(prefix: 'missing-', permission: false);
        $this->app['auth']->forgetGuards();
        $this->postUpload($missing, 'missing')->assertForbidden();
        $inactive = $this->graph(prefix: 'inactive-');
        DB::table('users')->where('user_id', $inactive['actor'])->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->postUpload($inactive, 'inactive')->assertForbidden();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $limited = $this->graph(prefix: 'limited-');
        $this->app['auth']->forgetGuards();
        $this->postUpload($limited, 'one')->assertCreated();
        $this->postUpload($limited, 'two')->assertTooManyRequests();
    }

    public function test_it_versions_schema_and_initiation_only_dcl(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_065800_create_sensor_dataset_upload_sessions.php'));
        $dcl = file_get_contents(database_path('sql/dcl/039_sensor_dataset_upload_initiation_grants.sql'));
        $this->assertIsString($migration);
        foreach (['sensor_dataset_upload_type_check', 'sensor_dataset_upload_status_check', 'sensor_dataset_upload_expiry_check'] as $guard) {
            $this->assertStringContainsString($guard, $migration);
        }$this->assertIsString($dcl);
        $this->assertStringContainsString('GRANT SELECT, INSERT', $dcl);
        $this->assertStringNotContainsString('UPDATE', $dcl);
        $this->assertStringNotContainsString('DELETE', $dcl);
    }

    private function postUpload(array $g, string $key, ?array $payload = null)
    {
        return $this->withToken($g['token'])->withHeader('Idempotency-Key', $key)->postJson('/api/v1/flights/'.$g['flight'].'/sensor-datasets/uploads', $payload ?? $this->payload());
    }

    private function payload(): array
    {
        return ['file_name' => ' coast.LAZ ', 'dataset_type' => ' LIDAR_POINT_CLOUD ', 'file_format' => ' LAZ ', 'sensor_id' => $this->currentSensor, 'file_size_bytes' => 4096, 'spatial_reference' => ' EPSG:4326 ', 'metadata' => ['points' => 1200]];
    }

    private string $currentSensor = '';

    private function graph(string $prefix = '', bool $permission = true, string $status = 'flying'): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        DB::table('organizations')->insert([['organization_id' => $org, 'organization_name' => $prefix.'Sensor Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $foreignOrg, 'organization_name' => $prefix.'Foreign Sensor Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($actor, $org, $prefix.'sensor@example.test');
        $this->user($foreignActor, $foreignOrg, $prefix.'foreign-sensor@example.test');
        $role = (string) Str::uuid();
        $permissionId = DB::table('permissions')->where('permission_code', 'media.upload')->value('permission_id') ?? (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => $prefix.'Sensor Uploader', 'role_code' => $prefix.'sensor_uploader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['permission_id' => $permissionId, 'permission_code' => 'media.upload', 'permission_name' => 'Upload media', 'created_at' => now(), 'updated_at' => now()]);
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }$local = $this->lineage($org, $actor, $prefix.'LOCAL', $status);
        $foreign = $this->lineage($foreignOrg, $foreignActor, $prefix.'FOREIGN', 'flying');
        $sensor = (string) Str::uuid();
        $foreignSensor = (string) Str::uuid();
        DB::table('drone_sensors')->insert([['sensor_id' => $sensor, 'drone_id' => $local['drone'], 'sensor_name' => 'LiDAR', 'sensor_type' => 'lidar', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['sensor_id' => $foreignSensor, 'drone_id' => $foreign['drone'], 'sensor_name' => 'Foreign LiDAR', 'sensor_type' => 'lidar', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->currentSensor = $sensor;

        return ['actor' => $actor, 'flight' => $local['flight'], 'foreign_flight' => $foreign['flight'], 'sensor' => $sensor, 'foreign_sensor' => $foreignSensor, 'token' => User::query()->findOrFail($actor)->createToken($prefix.'sensor')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Sensor', 'last_name' => 'Uploader', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lineage(string $org, string $actor, string $code, string $status): array
    {
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code.'-SITE', 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => $code.'-MSN', 'mission_title' => $code, 'mission_objective' => 'Upload sensor data.', 'mission_status' => 'in_progress', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => $code, 'model' => 'Test', 'serial_number' => $code.'-DRONE', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => $code.'-FLT', 'flight_status' => $status, 'quality_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return ['drone' => $drone, 'flight' => $flight];
    }
}
