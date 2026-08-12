<?php

namespace Tests\Feature\Mobile;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncDeviceRegisterTest extends TestCase
{
    use RefreshDatabase;

    // [SYNC-01] An active user registers one installation for offline sync.
    public function test_it_registers_a_sync_device(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-12T12:00:00Z'));
        $graph = $this->createGraph();
        $deviceId = (string) Str::uuid();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$graph['token'],
            'X-Request-ID' => 'req_sync_01_success',
            'User-Agent' => 'MangroScan Mobile Test',
        ])->postJson('/api/v1/mobile/devices/register', $this->payload($deviceId));

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => [
                    'device_id' => $deviceId,
                    'server_time' => '2026-08-12T12:00:00+00:00',
                ],
                'meta' => ['request_id' => 'req_sync_01_success'],
            ]);
        $this->assertDatabaseHas('sync_devices', [
            'device_id' => $deviceId,
            'user_id' => $graph['actor_id'],
            'platform' => 'android',
            'app_version' => '1.2.3',
            'device_name' => 'Field Tablet',
        ]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('sync.device.register', $audit->action);
        $this->assertNull($audit->old_values);
        $this->assertSame('android', $audit->new_values['platform']);
        $this->assertSame('req_sync_01_success', $audit->request_id);
    }

    // [SYNC-01] Retrying identical registration is idempotent.
    public function test_it_idempotently_retries_registration(): void
    {
        $graph = $this->createGraph();
        $deviceId = (string) Str::uuid();
        $uri = '/api/v1/mobile/devices/register';

        $this->withToken($graph['token'])->postJson($uri, $this->payload($deviceId))->assertCreated();
        $originalUpdatedAt = DB::table('sync_devices')->where('device_id', $deviceId)->value('updated_at');

        $this->travel(5)->minutes();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload($deviceId))
            ->assertCreated()
            ->assertJsonPath('data.device_id', $deviceId);

        $this->assertDatabaseCount('sync_devices', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertEquals($originalUpdatedAt, DB::table('sync_devices')->where('device_id', $deviceId)->value('updated_at'));
    }

    // [SYNC-01] Changed installation metadata refreshes the owned registration.
    public function test_it_refreshes_owned_device_metadata(): void
    {
        $graph = $this->createGraph();
        $deviceId = (string) Str::uuid();
        $uri = '/api/v1/mobile/devices/register';
        $this->withToken($graph['token'])->postJson($uri, $this->payload($deviceId))->assertCreated();

        $this->withToken($graph['token'])->postJson($uri, [
            'device_id' => $deviceId,
            'platform' => ' IOS ',
            'app_version' => ' 2.0.0 ',
            'device_name' => null,
        ])->assertCreated();

        $this->assertDatabaseHas('sync_devices', [
            'device_id' => $deviceId,
            'user_id' => $graph['actor_id'],
            'platform' => 'ios',
            'app_version' => '2.0.0',
            'device_name' => null,
        ]);
        $this->assertDatabaseCount('sync_devices', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = AuditLog::query()->get()
            ->first(fn (AuditLog $record): bool => $record->old_values !== null);
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame('android', $audit->old_values['platform']);
        $this->assertSame('ios', $audit->new_values['platform']);
    }

    // [SYNC-01] A device ID cannot move between authenticated accounts.
    public function test_it_rejects_a_device_owned_by_another_user(): void
    {
        $graph = $this->createGraph();
        $deviceId = (string) Str::uuid();
        DB::table('sync_devices')->insert([
            'device_id' => $deviceId,
            'user_id' => $graph['foreign_user_id'],
            'platform' => 'android',
            'app_version' => '1.0.0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/mobile/devices/register', $this->payload($deviceId))
            ->assertConflict()
            ->assertJsonPath('error.details.device_id', $deviceId);

        $this->assertDatabaseHas('sync_devices', [
            'device_id' => $deviceId,
            'user_id' => $graph['foreign_user_id'],
            'app_version' => '1.0.0',
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SYNC-01] Device identity and bounded client metadata are validated.
    public function test_it_validates_registration_input(): void
    {
        $graph = $this->createGraph();

        $this->withToken($graph['token'])
            ->postJson('/api/v1/mobile/devices/register', [
                'device_id' => 'not-a-uuid',
                'platform' => 'windows',
                'app_version' => str_repeat('a', 51),
                'device_name' => str_repeat('n', 101),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'device_id', 'platform', 'app_version', 'device_name',
            ], 'error.details');
    }

    // [SYNC-01] Authentication and an active tenant identity are mandatory.
    public function test_it_enforces_authentication_and_active_identity(): void
    {
        $graph = $this->createGraph();
        $uri = '/api/v1/mobile/devices/register';

        $this->postJson($uri, $this->payload((string) Str::uuid()))->assertUnauthorized();

        DB::table('organizations')->where('organization_id', $graph['organization_id'])->update([
            'status' => 'inactive',
        ]);
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload((string) Str::uuid()))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        $this->assertDatabaseCount('sync_devices', 0);
    }

    // [SYNC-01] Audit failure rolls back a new device registration.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->createGraph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])
            ->postJson('/api/v1/mobile/devices/register', $this->payload((string) Str::uuid()))
            ->assertInternalServerError();

        $this->assertDatabaseCount('sync_devices', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [SYNC-01] Throttling prevents a second registration attempt.
    public function test_it_rate_limits_device_registration(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();
        $uri = '/api/v1/mobile/devices/register';

        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload((string) Str::uuid()))
            ->assertCreated();
        $this->withToken($graph['token'])
            ->postJson($uri, $this->payload((string) Str::uuid()))
            ->assertTooManyRequests();
        $this->assertDatabaseCount('sync_devices', 1);
    }

    // [SYNC-01] The cursor-ready schema, platform guard and DCL are versioned.
    public function test_it_versions_sync_device_database_guards(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_063800_create_sync_devices_table.php'));
        $dcl = file_get_contents(database_path('sql/dcl/010_sync_device_grants.sql'));

        $this->assertIsString($migration);
        $this->assertStringContainsString('sync_devices_platform_check', $migration);
        $this->assertStringContainsString("'android', 'ios', 'web'", $migration);
        $this->assertStringContainsString("\$table->text('last_cursor')->nullable();", $migration);
        $this->assertStringContainsString("\$table->timestampTz('last_sync_at')->nullable();", $migration);
        $this->assertIsString($dcl);
        $this->assertStringContainsString(
            'GRANT SELECT, INSERT, UPDATE ON TABLE app.sync_devices TO mangroscan_api_rw;',
            $dcl,
        );
        $this->assertStringNotContainsString('mangroscan_report_ro', $dcl);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $graph = $this->createGraph('constraint-');
        $this->expectExceptionMessage('sync_devices_platform_check');
        DB::table('sync_devices')->insert([
            'device_id' => (string) Str::uuid(),
            'user_id' => $graph['actor_id'],
            'platform' => 'windows',
            'app_version' => '1.0.0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function payload(string $deviceId): array
    {
        return [
            'device_id' => $deviceId,
            'platform' => ' Android ',
            'app_version' => ' 1.2.3 ',
            'device_name' => ' Field Tablet ',
        ];
    }

    /** @return array<string, string> */
    private function createGraph(string $prefix = ''): array
    {
        $organizationId = (string) Str::uuid();
        $foreignOrganizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $foreignUserId = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $organizationId, 'organization_name' => $prefix.'Sync Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrganizationId, 'organization_name' => $prefix.'Foreign Sync Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->insertUser($actorId, $organizationId, $prefix.'sync@example.test');
        $this->insertUser($foreignUserId, $foreignOrganizationId, $prefix.'foreign-sync@example.test');

        return [
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'foreign_user_id' => $foreignUserId,
            'token' => User::query()->findOrFail($actorId)->createToken('Sync device test', ['*'], now()->addHour())->plainTextToken,
        ];
    }

    private function insertUser(string $id, string $organizationId, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Mobile',
            'last_name' => 'User',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
