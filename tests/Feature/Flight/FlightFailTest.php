<?php

namespace Tests\Feature\Flight;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FlightFailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aborts_or_fails_an_active_flight_with_audit_evidence(): void
    {
        foreach (['aborted', 'failed'] as $status) {
            $g = $this->graph();
            $r = $this->withToken($g['token'])->withHeader('X-Request-ID', 'req_flt_07')->postJson('/api/v1/flights/'.$g['flight'].'/fail', [
                'status' => Str::upper($status), 'reason' => ' Weather hazard ', 'ended_at' => '2026-08-12T17:00:00+08:00',
            ]);
            $r->assertOk()->assertJsonPath('data.status', $status)->assertJsonPath('data.ended_at', '2026-08-12T09:00:00+00:00')
                ->assertJsonPath('data.flight_duration_minutes', '60.00')->assertJsonPath('data.notes', 'Weather hazard')
                ->assertJsonPath('meta.request_id', 'req_flt_07');
            $audit = AuditLog::query()->latest('created_at')->firstOrFail();
            $this->assertSame('flight.fail', $audit->action);
            $this->assertSame('Weather hazard', $audit->new_values['reason']);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_defaults_end_time_to_server_time(): void
    {
        Carbon::setTestNow('2026-08-12T10:00:00Z');
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', ['status' => 'failed', 'reason' => 'Motor fault'])
            ->assertOk()->assertJsonPath('data.ended_at', '2026-08-12T10:00:00+00:00')->assertJsonPath('data.flight_duration_minutes', '120.00');
    }

    public function test_it_validates_status_reason_and_time(): void
    {
        $g = $this->graph();
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', [
            'status' => 'completed', 'reason' => ' ', 'ended_at' => 'bad',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status', 'reason', 'ended_at'], 'error.details');
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', [
            'status' => 'failed', 'reason' => 'Fault', 'ended_at' => '2026-08-12T08:00:00Z',
        ])->assertConflict()->assertJsonPath('error.details.started_at', '2026-08-12T08:00:00+00:00');
    }

    public function test_it_requires_a_started_flying_flight(): void
    {
        foreach (['planned', 'completed', 'aborted', 'failed'] as $status) {
            $g = $this->graph(status: $status);
            $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', ['status' => 'failed', 'reason' => 'Fault'])
                ->assertConflict()->assertJsonPath('error.details.current_status', $status);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_it_hides_unavailable_flights_and_rolls_back_audit_failure(): void
    {
        $g = $this->graph();
        foreach ([$g['foreign_flight'], $g['deleted_flight'], (string) Str::uuid(), 'bad'] as $id) {
            $this->withToken($g['token'])->postJson('/api/v1/flights/'.$id.'/fail', ['status' => 'failed', 'reason' => 'Fault'])->assertNotFound();
        }
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', ['status' => 'failed', 'reason' => 'Fault'])->assertInternalServerError();
        $this->assertDatabaseHas('flight_sessions', ['flight_session_id' => $g['flight'], 'flight_status' => 'flying', 'ended_at' => null]);
    }

    public function test_it_enforces_permission_and_throttling(): void
    {
        $g = $this->graph(permission: false);
        $this->postJson('/api/v1/flights/'.$g['flight'].'/fail', ['status' => 'failed', 'reason' => 'Fault'])->assertUnauthorized();
        $this->withToken($g['token'])->postJson('/api/v1/flights/'.$g['flight'].'/fail', ['status' => 'failed', 'reason' => 'Fault'])
            ->assertForbidden()->assertJsonPath('error.details.required_permission', 'flights.complete');
        $this->app['auth']->forgetGuards();
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $active = $this->graph();
        $url = '/api/v1/flights/'.$active['flight'].'/fail';
        $this->withToken($active['token'])->postJson($url, ['status' => 'failed', 'reason' => 'Fault'])->assertOk();
        $this->withToken($active['token'])->postJson($url, ['status' => 'failed', 'reason' => 'Fault'])->assertTooManyRequests();
    }

    /** @return array<string, string> */
    private function graph(string $status = 'flying', bool $permission = true): array
    {
        $o = (string) Str::uuid();
        $fo = (string) Str::uuid();
        $u = (string) Str::uuid();
        $fu = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));
        DB::table('organizations')->insert([['organization_id' => $o, 'organization_name' => 'Fail '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['organization_id' => $fo, 'organization_name' => 'Foreign Fail '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        $this->user($u, $o);
        $this->user($fu, $fo);
        $p = DB::table('permissions')->where('permission_code', 'flights.complete')->value('permission_id') ?? (string) Str::uuid();
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $o, 'role_name' => 'Failure Handler', 'role_code' => 'failure-'.Str::lower(Str::random(8)), 'created_at' => now(), 'updated_at' => now()]);
        if (! DB::table('permissions')->where('permission_id', $p)->exists()) {
            DB::table('permissions')->insert(['permission_id' => $p, 'permission_code' => 'flights.complete', 'permission_name' => 'Complete flights', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        $s = (string) Str::uuid();
        $fs = (string) Str::uuid();
        $ds = (string) Str::uuid();
        $this->site($s, $o, $u, 'FAIL-S-'.$suffix);
        $this->site($fs, $fo, $fu, 'FFAIL-S-'.$suffix);
        $this->site($ds, $o, $u, 'DFAIL-S-'.$suffix, true);
        $m = (string) Str::uuid();
        $fm = (string) Str::uuid();
        $dm = (string) Str::uuid();
        $this->mission($m, $s, $u, 'FAIL-M-'.$suffix);
        $this->mission($fm, $fs, $fu, 'FFAIL-M-'.$suffix);
        $this->mission($dm, $ds, $u, 'DFAIL-M-'.$suffix);
        $d = (string) Str::uuid();
        $fd = (string) Str::uuid();
        $this->drone($d, $o, 'FAIL-D-'.$suffix);
        $this->drone($fd, $fo, 'FFAIL-D-'.$suffix);
        $f = (string) Str::uuid();
        $ff = (string) Str::uuid();
        $df = (string) Str::uuid();
        $this->flight($f, $m, $d, $u, 'FAIL-F-'.$suffix, $status);
        $this->flight($ff, $fm, $fd, $fu, 'FFAIL-F-'.$suffix, 'flying');
        $this->flight($df, $dm, $d, $u, 'DFAIL-F-'.$suffix, 'flying');

        return ['flight' => $f, 'foreign_flight' => $ff, 'deleted_flight' => $df, 'token' => User::findOrFail($u)->createToken('fail')->plainTextToken];
    }

    private function user(string $id, string $o): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $o, 'first_name' => 'F', 'last_name' => 'H', 'email' => Str::uuid().'@test', 'password' => Hash::make('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $o, string $u, string $c, bool $deleted = false): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $o, 'site_name' => $c, 'site_code' => $c, 'province' => 'P', 'city_municipality' => 'C', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $u, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }

    private function mission(string $id, string $s, string $u, string $c): void
    {
        DB::table('survey_missions')->insert(['mission_id' => $id, 'site_id' => $s, 'mission_code' => $c, 'mission_title' => $c, 'mission_objective' => 'O', 'mission_status' => 'in_progress', 'actual_start_at' => '2026-08-12T07:00:00Z', 'created_by' => $u, 'approved_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function drone(string $id, string $o, string $serial): void
    {
        DB::table('drones')->insert(['drone_id' => $id, 'organization_id' => $o, 'drone_name' => $serial, 'serial_number' => $serial, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function flight(string $id, string $m, string $d, string $u, string $c, string $status): void
    {
        DB::table('flight_sessions')->insert(['flight_session_id' => $id, 'mission_id' => $m, 'drone_id' => $d, 'pilot_user_id' => $u, 'flight_code' => $c, 'flight_status' => $status, 'quality_status' => 'pending', 'started_at' => $status === 'flying' ? '2026-08-12T08:00:00Z' : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
