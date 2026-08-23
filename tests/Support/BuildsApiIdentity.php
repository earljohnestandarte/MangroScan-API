<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait BuildsApiIdentity
{
    /** @param list<string> $permissions
     * @return array{organization_id:string, actor_id:string, token:string}
     */
    protected function apiIdentity(array $permissions, string $prefix = ''): array
    {
        $organizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => $prefix.'API Task Org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'user_id' => $actorId,
            'organization_id' => $organizationId,
            'first_name' => 'API',
            'last_name' => 'Task User',
            'email' => $prefix.'api-task@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            'role_id' => $roleId,
            'organization_id' => $organizationId,
            'role_name' => $prefix.'API Task Role',
            'role_code' => $prefix.'api_task_role',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($permissions as $code) {
            $permissionId = DB::table('permissions')->where('permission_code', $code)->value('permission_id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'permission_id' => $permissionId,
                    'permission_code' => $code,
                    'permission_name' => $code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_roles')->insert([
            'user_id' => $actorId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'token' => User::query()->findOrFail($actorId)->createToken($prefix.'api-task')->plainTextToken,
        ];
    }

    protected function organization(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('organizations')->insert([
            'organization_id' => $id, 'organization_name' => $name, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function user(string $organizationId, string $email): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organizationId,
            'first_name' => 'Related', 'last_name' => 'User', 'email' => $email,
            'password' => Hash::make('password'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{site_id:string,mission_id:string,flight_id:string} */
    protected function missionLineage(string $organizationId, string $actorId, string $prefix): array
    {
        $siteId = (string) Str::uuid();
        $missionId = (string) Str::uuid();
        $droneId = (string) Str::uuid();
        $flightId = (string) Str::uuid();
        DB::table('survey_sites')->insert([
            'site_id' => $siteId, 'organization_id' => $organizationId,
            'site_name' => $prefix.' Site', 'site_code' => $prefix.'-SITE',
            'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('survey_missions')->insert([
            'mission_id' => $missionId, 'site_id' => $siteId,
            'mission_code' => $prefix.'-MSN', 'mission_title' => $prefix.' Mission',
            'mission_objective' => 'Endpoint verification.', 'mission_status' => 'completed',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('drones')->insert([
            'drone_id' => $droneId, 'organization_id' => $organizationId,
            'drone_name' => $prefix.' Drone', 'serial_number' => $prefix.'-DRONE',
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('flight_sessions')->insert([
            'flight_session_id' => $flightId, 'mission_id' => $missionId, 'drone_id' => $droneId,
            'pilot_user_id' => $actorId, 'flight_code' => $prefix.'-FLT',
            'flight_status' => 'completed', 'quality_status' => 'acceptable',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['site_id' => $siteId, 'mission_id' => $missionId, 'flight_id' => $flightId];
    }
}
