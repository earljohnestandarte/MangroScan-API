<?php

namespace Tests\Feature\Site;

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

class PlotUpdateTest extends TestCase
{
    use RefreshDatabase;

    // [PLOT-03] A plot manager can update metadata and soft-archive a plot.
    public function test_it_updates_and_archives_a_plot(): void
    {
        $graph = $this->graph();
        $response = $this->withToken($graph['token'])->withHeader('X-Request-ID', 'req_plot_03')
            ->patchJson('/api/v1/plots/'.$graph['plot'], [
                'plot_code' => ' updated-01 ',
                'plot_name' => ' Updated plot ',
                'description' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.plot_code', 'UPDATED-01')
            ->assertJsonPath('data.plot_name', 'Updated plot')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('meta.request_id', 'req_plot_03');

        $this->assertDatabaseHas('monitoring_plots', [
            'plot_id' => $graph['plot'],
            'plot_code' => 'UPDATED-01',
            'deleted_at' => null,
        ]);
        $this->assertSame('plot.update', AuditLog::query()->sole()->action);

        $this->withToken($graph['token'])->patchJson('/api/v1/plots/'.$graph['plot'], ['archive' => true])
            ->assertOk()->assertJsonPath('data.plot_code', 'UPDATED-01');
        $this->assertSoftDeleted('monitoring_plots', ['plot_id' => $graph['plot']]);
    }

    // [PLOT-03] Foreign, missing, deleted and malformed plots are hidden.
    public function test_it_hides_unavailable_plots(): void
    {
        $graph = $this->graph();
        foreach ([$graph['foreign_plot'], (string) Str::uuid(), 'not-a-uuid'] as $plot) {
            $this->withToken($graph['token'])->patchJson('/api/v1/plots/'.$plot, ['plot_name' => 'No'])
                ->assertNotFound();
        }

        DB::table('monitoring_plots')->where('plot_id', $graph['plot'])->update(['deleted_at' => now()]);
        $this->withToken($graph['token'])->patchJson('/api/v1/plots/'.$graph['plot'], ['plot_name' => 'No'])
            ->assertNotFound();
    }

    // [PLOT-03] Audit failure rolls back the update.
    public function test_it_rolls_back_when_audit_fails(): void
    {
        $graph = $this->graph();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->withToken($graph['token'])->patchJson('/api/v1/plots/'.$graph['plot'], ['plot_name' => 'Changed'])
            ->assertInternalServerError();
        $this->assertDatabaseHas('monitoring_plots', ['plot_id' => $graph['plot'], 'plot_name' => 'Original plot']);
    }

    /** @return array<string, string> */
    private function graph(): array
    {
        $org = (string) Str::uuid();
        $foreignOrg = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $foreignActor = (string) Str::uuid();
        $role = (string) Str::uuid();
        $permission = (string) Str::uuid();
        $plot = (string) Str::uuid();
        $foreignPlot = (string) Str::uuid();
        $site = (string) Str::uuid();
        $foreignSite = (string) Str::uuid();
        DB::table('organizations')->insert([
            ['organization_id' => $org, 'organization_name' => 'Plot Update Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $foreignOrg, 'organization_name' => 'Foreign Plot Org', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($actor, $org, 'plot-update@example.test');
        $this->user($foreignActor, $foreignOrg, 'foreign-plot-update@example.test');
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Plot Manager', 'role_code' => 'plot_update_manager', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['permission_id' => $permission, 'permission_code' => 'plots.manage', 'permission_name' => 'Manage plots', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->site($site, $org, $actor, 'SITE-UPDATE');
        $this->site($foreignSite, $foreignOrg, $foreignActor, 'SITE-FOREIGN');
        $this->plot($plot, $site, 'PLOT-001', 'Original plot');
        $this->plot($foreignPlot, $foreignSite, 'FOREIGN-001', 'Foreign plot');

        return ['plot' => $plot, 'foreign_plot' => $foreignPlot, 'token' => User::findOrFail($actor)->createToken('plot-update')->plainTextToken];
    }

    private function user(string $id, string $org, string $email): void
    {
        DB::table('users')->insert(['user_id' => $id, 'organization_id' => $org, 'first_name' => 'Plot', 'last_name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $id, string $org, string $actor, string $code): void
    {
        DB::table('survey_sites')->insert(['site_id' => $id, 'organization_id' => $org, 'site_name' => $code, 'site_code' => $code, 'province' => 'Cebu', 'city_municipality' => 'Cebu City', 'environment_type' => 'coastal', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plot(string $id, string $site, string $code, string $name): void
    {
        DB::table('monitoring_plots')->insert(['plot_id' => $id, 'site_id' => $site, 'plot_code' => $code, 'plot_name' => $name, 'plot_geom' => json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
