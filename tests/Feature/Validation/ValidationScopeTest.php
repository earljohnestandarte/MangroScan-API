<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidationScopeTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-01] The exact envelope resolves sites and plots beneath their tenant missions.
    public function test_it_returns_validation_scope_options_with_exact_safe_fields(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_val_01')
            ->getJson('/api/v1/validation/scopes');

        $response->assertOk()->assertHeader('X-Request-ID', 'req_val_01')
            ->assertJsonCount(4, 'data')
            ->assertJsonCount(1, 'data.missions')
            ->assertJsonCount(2, 'data.missions.0.plots')
            ->assertJsonCount(1, 'data.species')
            ->assertJsonCount(1, 'data.assignees')
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.missions.0.mission_id', $graph['mission_id'])
            ->assertJsonPath('data.missions.0.site.site_id', $graph['site_id'])
            ->assertJsonPath('data.missions.0.plots.0.plot_id', $graph['alpha_plot_id'])
            ->assertJsonPath('data.species.0.species_id', $graph['species_id'])
            ->assertJsonPath('data.assignees.0.user_id', $graph['assignee_id'])
            ->assertJsonPath('data.assignees.0.display_name', 'Ana Maria Validator')
            ->assertJsonPath('data.sessions.0.validation_session_id', $graph['session_id']);

        $this->assertSame(['missions', 'species', 'assignees', 'sessions'], array_keys($response->json('data')));
        $this->assertSame(
            ['mission_id', 'mission_code', 'mission_title', 'status', 'site', 'plots'],
            array_keys($response->json('data.missions.0')),
        );
        $this->assertSame(['site_id', 'site_code', 'site_name'], array_keys($response->json('data.missions.0.site')));
        $this->assertSame(['plot_id', 'plot_code', 'plot_name'], array_keys($response->json('data.missions.0.plots.0')));
        $this->assertSame(['species_id', 'scientific_name', 'common_name', 'local_name'], array_keys($response->json('data.species.0')));
        $this->assertSame(['user_id', 'display_name', 'position_title'], array_keys($response->json('data.assignees.0')));
        $this->assertSame([
            'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
            'validation_date', 'method', 'status', 'notes', 'completed_at', 'completed_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data.sessions.0')));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // [VAL-01] Foreign, deleted, inactive and inconsistent records never become options.
    public function test_it_enforces_tenant_lineage_and_option_eligibility(): void
    {
        $graph = $this->createGraph();
        $response = $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertOk();

        $payload = $response->json('data');
        $this->assertNotContains($graph['foreign_mission_id'], array_column($payload['missions'], 'mission_id'));
        $this->assertNotContains($graph['deleted_mission_id'], array_column($payload['missions'], 'mission_id'));
        $this->assertNotContains($graph['deleted_plot_id'], array_column($payload['missions'][0]['plots'], 'plot_id'));
        $this->assertNotContains($graph['inactive_species_id'], array_column($payload['species'], 'species_id'));
        $this->assertNotContains($graph['inactive_assignee_id'], array_column($payload['assignees'], 'user_id'));
        $this->assertNotContains($graph['unqualified_user_id'], array_column($payload['assignees'], 'user_id'));
        $this->assertNotContains($graph['foreign_assignee_id'], array_column($payload['assignees'], 'user_id'));
        $this->assertNotContains($graph['foreign_session_id'], array_column($payload['sessions'], 'validation_session_id'));
        $this->assertNotContains($graph['inconsistent_session_id'], array_column($payload['sessions'], 'validation_session_id'));
    }

    // [VAL-01] Authentication and a current/global validation.read grant are mandatory.
    public function test_it_enforces_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/validation/scopes')->assertUnauthorized();

        $graph = $this->createGraph();
        DB::table('role_permissions')->where('role_id', $graph['reader_role_id'])->delete();
        $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'validation.read');

        DB::table('role_permissions')->insert([
            'role_id' => $graph['foreign_creator_role_id'],
            'permission_id' => $graph['read_permission_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $graph['actor_id'],
            'role_id' => $graph['foreign_creator_role_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertForbidden()
            ->assertJsonPath('error.details.required_permission', 'validation.read');
    }

    // [VAL-01] Inactive identities cannot enumerate validation options.
    public function test_it_rejects_an_inactive_identity(): void
    {
        $graph = $this->createGraph();
        DB::table('users')->where('user_id', $graph['actor_id'])->update(['status' => 'inactive']);

        $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    // [VAL-01] Validation scope reads share the authenticated request budget.
    public function test_it_rate_limits_validation_scope_reads(): void
    {
        config(['mangroscan.auth.authenticated_requests_per_minute' => 1]);
        $graph = $this->createGraph();

        $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertOk();
        $this->withToken($graph['token'])->getJson('/api/v1/validation/scopes')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // [VAL-01] The route and every required runtime SELECT grant are versioned.
    public function test_it_registers_the_route_and_uses_existing_least_privilege_dcl(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/validation/scopes');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertContains('permission:validation.read', $route->gatherMiddleware());

        $grants = [
            '002_identity_and_audit_grants.sql' => ['app.users', 'app.roles', 'app.permissions', 'app.role_permissions', 'app.user_roles'],
            '003_survey_site_grants.sql' => ['app.survey_sites'],
            '005_survey_mission_grants.sql' => ['app.survey_missions'],
            '014_monitoring_plot_grants.sql' => ['app.monitoring_plots'],
            '034_tree_observation_grants.sql' => ['app.mangrove_species'],
            '046_jason_workflow_grants.sql' => ['app.validation_sessions'],
        ];
        foreach ($grants as $file => $tables) {
            $sql = file_get_contents(database_path('sql/dcl/'.$file));
            $this->assertIsString($sql);
            $this->assertStringContainsString('GRANT SELECT', $sql);
            $this->assertStringContainsString('mangroscan_api_rw', $sql);
            foreach ($tables as $table) {
                $this->assertStringContainsString($table, $sql);
            }
        }
    }

    /** @return array<string, string> */
    private function createGraph(): array
    {
        $ids = collect([
            'organization_id', 'foreign_organization_id', 'actor_id', 'assignee_id',
            'inactive_assignee_id', 'unqualified_user_id', 'foreign_assignee_id',
            'reader_role_id', 'creator_role_id', 'foreign_creator_role_id',
            'read_permission_id', 'create_permission_id', 'site_id', 'foreign_site_id',
            'mission_id', 'foreign_mission_id', 'deleted_mission_id', 'alpha_plot_id',
            'beta_plot_id', 'deleted_plot_id', 'species_id', 'inactive_species_id',
            'session_id', 'foreign_session_id', 'inconsistent_session_id',
        ])->mapWithKeys(fn (string $key): array => [$key => (string) Str::uuid()])->all();
        $suffix = Str::lower(Str::random(8));

        DB::table('organizations')->insert([
            ['organization_id' => $ids['organization_id'], 'organization_name' => 'Validation '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $ids['foreign_organization_id'], 'organization_name' => 'Foreign validation '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user($ids['actor_id'], $ids['organization_id'], 'Scope', null, 'Reader', 'active', 'scope-'.$suffix.'@example.test');
        $this->user($ids['assignee_id'], $ids['organization_id'], 'Ana', 'Maria', 'Validator', 'active', 'assignee-'.$suffix.'@example.test');
        $this->user($ids['inactive_assignee_id'], $ids['organization_id'], 'Inactive', null, 'Validator', 'inactive', 'inactive-'.$suffix.'@example.test');
        $this->user($ids['unqualified_user_id'], $ids['organization_id'], 'No', null, 'Grant', 'active', 'unqualified-'.$suffix.'@example.test');
        $this->user($ids['foreign_assignee_id'], $ids['foreign_organization_id'], 'Foreign', null, 'Validator', 'active', 'foreign-'.$suffix.'@example.test');

        DB::table('roles')->insert([
            ['role_id' => $ids['reader_role_id'], 'organization_id' => $ids['organization_id'], 'role_name' => 'Validation reader '.$suffix, 'role_code' => 'reader_'.$suffix, 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $ids['creator_role_id'], 'organization_id' => $ids['organization_id'], 'role_name' => 'Validation creator '.$suffix, 'role_code' => 'creator_'.$suffix, 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $ids['foreign_creator_role_id'], 'organization_id' => $ids['foreign_organization_id'], 'role_name' => 'Foreign creator '.$suffix, 'role_code' => 'foreign_creator_'.$suffix, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            ['permission_id' => $ids['read_permission_id'], 'permission_code' => 'validation.read', 'permission_name' => 'Read validation data', 'created_at' => now(), 'updated_at' => now()],
            ['permission_id' => $ids['create_permission_id'], 'permission_code' => 'validation.create', 'permission_name' => 'Create validation sessions', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('role_permissions')->insert([
            ['role_id' => $ids['reader_role_id'], 'permission_id' => $ids['read_permission_id'], 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $ids['creator_role_id'], 'permission_id' => $ids['create_permission_id'], 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $ids['foreign_creator_role_id'], 'permission_id' => $ids['create_permission_id'], 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('user_roles')->insert([
            ['user_id' => $ids['actor_id'], 'role_id' => $ids['reader_role_id'], 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $ids['assignee_id'], 'role_id' => $ids['creator_role_id'], 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $ids['inactive_assignee_id'], 'role_id' => $ids['creator_role_id'], 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $ids['foreign_assignee_id'], 'role_id' => $ids['foreign_creator_role_id'], 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->site($ids['site_id'], $ids['organization_id'], $ids['actor_id'], 'VAL-'.$suffix);
        $this->site($ids['foreign_site_id'], $ids['foreign_organization_id'], $ids['foreign_assignee_id'], 'FOREIGN-'.$suffix);
        $this->mission($ids['mission_id'], $ids['site_id'], $ids['actor_id'], 'A-MSN-'.$suffix);
        $this->mission($ids['foreign_mission_id'], $ids['foreign_site_id'], $ids['foreign_assignee_id'], 'F-MSN-'.$suffix);
        $this->mission($ids['deleted_mission_id'], $ids['site_id'], $ids['actor_id'], 'Z-MSN-'.$suffix, true);
        $this->plot($ids['beta_plot_id'], $ids['site_id'], 'PLOT-B-'.$suffix, 'Beta Plot');
        $this->plot($ids['alpha_plot_id'], $ids['site_id'], 'PLOT-A-'.$suffix, 'Alpha Plot');
        $this->plot($ids['deleted_plot_id'], $ids['site_id'], 'PLOT-Z-'.$suffix, 'Deleted Plot', true);

        DB::table('mangrove_species')->insert([
            ['species_id' => $ids['species_id'], 'scientific_name' => 'Avicennia marina '.$suffix, 'common_name' => 'Grey mangrove', 'local_name' => 'Bungalon', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['species_id' => $ids['inactive_species_id'], 'scientific_name' => 'Inactive '.$suffix, 'common_name' => null, 'local_name' => null, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->validationSession($ids['session_id'], $ids['mission_id'], $ids['site_id'], $ids['alpha_plot_id'], $ids['assignee_id'], '2026-08-24');
        $this->validationSession($ids['foreign_session_id'], $ids['foreign_mission_id'], $ids['foreign_site_id'], null, $ids['foreign_assignee_id'], '2026-08-23');
        $this->validationSession($ids['inconsistent_session_id'], $ids['mission_id'], $ids['foreign_site_id'], null, $ids['actor_id'], '2026-08-22');

        /** @var User $actor */
        $actor = User::query()->findOrFail($ids['actor_id']);

        return $ids + ['token' => $actor->createToken('validation-scope')->plainTextToken];
    }

    private function user(string $id, string $organization, string $first, ?string $middle, string $last, string $status, string $email): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'organization_id' => $organization, 'first_name' => $first,
            'middle_name' => $middle, 'last_name' => $last, 'position_title' => 'Environmental Specialist',
            'email' => $email, 'password' => Hash::make('password'), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $id, string $organization, string $creator, string $code): void
    {
        DB::table('survey_sites')->insert([
            'site_id' => $id, 'organization_id' => $organization, 'site_name' => 'Site '.$code,
            'site_code' => $code, 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $creator,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mission(string $id, string $site, string $creator, string $code, bool $deleted = false): void
    {
        DB::table('survey_missions')->insert([
            'mission_id' => $id, 'site_id' => $site, 'mission_code' => $code,
            'mission_title' => 'Mission '.$code, 'mission_objective' => 'Validate scope options.',
            'mission_status' => 'completed', 'created_by' => $creator,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function plot(string $id, string $site, string $code, string $name, bool $deleted = false): void
    {
        $polygon = json_encode(['type' => 'Polygon', 'coordinates' => [[[123.8, 10.1], [123.9, 10.1], [123.9, 10.2], [123.8, 10.1]]]], JSON_THROW_ON_ERROR);
        $values = [$id, $site, $code, $name, $polygon, now(), now(), $deleted ? now() : null];
        if (DB::getDriverName() === 'pgsql') {
            DB::insert('INSERT INTO monitoring_plots (plot_id, site_id, plot_code, plot_name, plot_geom, created_at, updated_at, deleted_at) VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ?, ?, ?)', $values);

            return;
        }
        DB::table('monitoring_plots')->insert(array_combine(
            ['plot_id', 'site_id', 'plot_code', 'plot_name', 'plot_geom', 'created_at', 'updated_at', 'deleted_at'],
            $values,
        ));
    }

    private function validationSession(string $id, string $mission, string $site, ?string $plot, string $validator, string $date): void
    {
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $id, 'mission_id' => $mission, 'site_id' => $site,
            'plot_id' => $plot, 'validated_by' => $validator, 'validation_date' => $date,
            'method' => 'ground_survey', 'status' => 'open', 'notes' => 'Scope option.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
