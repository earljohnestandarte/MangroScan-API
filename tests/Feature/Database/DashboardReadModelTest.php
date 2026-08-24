<?php

namespace Tests\Feature\Database;

use App\Services\Dashboard\DashboardReadModelRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardReadModelTest extends TestCase
{
    use RefreshDatabase;

    // [V-08] The read model selects the latest value for each mission/metric pair.
    public function test_it_projects_only_the_latest_mission_accuracy_metric(): void
    {
        $graph = $this->missionGraph('LATEST');
        $olderId = (string) Str::uuid();
        $newerId = (string) Str::uuid();

        $this->accuracy($olderId, $graph['mission'], 'species_accuracy', '0.610000', '2026-08-24 08:00:00');
        $this->accuracy($newerId, $graph['mission'], 'species_accuracy', '0.930000', '2026-08-24 09:00:00');

        $row = DB::table('v_mission_accuracy_summary')
            ->where('mission_id', $graph['mission'])
            ->where('metric_type', 'species_accuracy')
            ->sole();

        $this->assertSame($newerId, $row->accuracy_metric_id);
        $this->assertSame(0.93, (float) $row->metric_value);
        $this->assertSame(1, DB::table('v_mission_accuracy_summary')->where('mission_id', $graph['mission'])->count());
    }

    // [MV-01] Dashboard aggregates are accurate, tenant-keyed, and soft-delete safe.
    public function test_it_projects_mission_metrics_without_cross_tenant_or_join_inflation(): void
    {
        $local = $this->missionGraph('LOCAL');
        $foreign = $this->missionGraph('FOREIGN');
        $deleted = $this->missionGraph('DELETED', deleted: true);
        $speciesA = $this->species('Rhizophora mucronata');
        $speciesB = $this->species('Avicennia marina');

        $this->tree($local, 'LOCAL-TREE-1', $speciesA, 'validated');
        $this->tree($local, 'LOCAL-TREE-2', $speciesB, 'corrected');
        $this->tree($local, 'LOCAL-TREE-3', null, 'unvalidated');
        $this->tree($local, 'LOCAL-TREE-DELETED', $speciesA, 'rejected', deleted: true);
        $this->tree($foreign, 'FOREIGN-TREE-1', $speciesA, 'rejected');

        $openSession = $this->validationSession($local, 'open');
        $completedSession = $this->validationSession($local, 'completed');
        $this->groundTruth($openSession, $speciesA);
        $this->groundTruth($completedSession, $speciesB);
        $this->groundTruth($completedSession, null);

        $this->processingJob($local['mission'], 'queued');
        $this->processingJob($local['mission'], 'completed');
        $this->processingJob($local['mission'], 'failed');
        $this->accuracy((string) Str::uuid(), $local['mission'], 'count_f1', '0.875000', '2026-08-24 10:00:00');

        app(DashboardReadModelRefresher::class)->refresh(false);

        $row = DB::table('mv_dashboard_mission_metrics')->where('mission_id', $local['mission'])->sole();

        $this->assertSame($local['organization'], $row->organization_id);
        $this->assertSame($local['site'], $row->site_id);
        $this->assertSame(3, (int) $row->tree_count);
        $this->assertSame(2, (int) $row->species_count);
        $this->assertSame(2, (int) $row->validated_tree_count);
        $this->assertSame(1, (int) $row->unvalidated_tree_count);
        $this->assertSame(0, (int) $row->rejected_tree_count);
        $this->assertSame(2, (int) $row->validation_session_count);
        $this->assertSame(1, (int) $row->open_validation_session_count);
        $this->assertSame(1, (int) $row->completed_validation_session_count);
        $this->assertSame(3, (int) $row->ground_truth_count);
        $this->assertSame(3, (int) $row->processing_job_count);
        $this->assertSame(1, (int) $row->queued_processing_job_count);
        $this->assertSame(1, (int) $row->completed_processing_job_count);
        $this->assertSame(1, (int) $row->failed_processing_job_count);
        $this->assertSame(0.875, (float) $row->count_f1);

        $this->assertSame(1, DB::table('mv_dashboard_mission_metrics')->where('organization_id', $local['organization'])->count());
        $this->assertSame(1, DB::table('mv_dashboard_mission_metrics')->where('organization_id', $foreign['organization'])->count());
        $this->assertFalse(DB::table('mv_dashboard_mission_metrics')->where('mission_id', $deleted['mission'])->exists());
    }

    // [V-08/MV-01] SQL, refresh, indexing, and least-privilege grants stay versioned.
    public function test_it_versions_the_dashboard_read_models_refresh_and_grants(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_25_000000_create_dashboard_read_models.php'));
        $service = file_get_contents(app_path('Services/Dashboard/DashboardReadModelRefresher.php'));
        $console = file_get_contents(base_path('routes/console.php'));
        $dcl = file_get_contents(database_path('sql/dcl/049_dashboard_read_model_grants.sql'));

        $this->assertIsString($migration);
        foreach (['CREATE VIEW v_mission_accuracy_summary', 'ROW_NUMBER() OVER', 'CREATE MATERIALIZED VIEW mv_dashboard_mission_metrics AS', 'CREATE UNIQUE INDEX mv_dashboard_mission_metrics_mission_id_unique', 'site.organization_id', 'tree.deleted_at IS NULL', 'mission.deleted_at IS NULL'] as $fragment) {
            $this->assertStringContainsString($fragment, $migration);
        }

        $this->assertIsString($service);
        $this->assertStringContainsString('REFRESH MATERIALIZED VIEW{$modifier} mv_dashboard_mission_metrics', $service);
        $this->assertStringContainsString('DB::transactionLevel() === 0', $service);
        $this->assertIsString($console);
        $this->assertStringContainsString("Artisan::command('dashboard:refresh'", $console);

        $this->assertIsString($dcl);
        $this->assertStringContainsString('REVOKE ALL ON TABLE app.v_mission_accuracy_summary FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;', $dcl);
        $this->assertStringContainsString('REVOKE ALL ON TABLE app.mv_dashboard_mission_metrics FROM PUBLIC, mangroscan_api_rw, mangroscan_worker, mangroscan_report_ro, mangroscan_auditor;', $dcl);
        $this->assertStringContainsString('GRANT SELECT ON TABLE app.v_mission_accuracy_summary, app.mv_dashboard_mission_metrics TO mangroscan_api_rw, mangroscan_report_ro;', $dcl);
        foreach (['GRANT INSERT', 'GRANT UPDATE', 'GRANT DELETE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dcl);
        }
    }

    /** @return array{organization: string, user: string, site: string, mission: string, drone: string, flight: string} */
    private function missionGraph(string $code, bool $deleted = false): array
    {
        $organization = (string) Str::uuid();
        $user = (string) Str::uuid();
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        $now = now('UTC');

        DB::table('organizations')->insert(['organization_id' => $organization, 'organization_name' => "$code Organization", 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('users')->insert(['user_id' => $user, 'organization_id' => $organization, 'first_name' => $code, 'last_name' => 'User', 'email' => strtolower($code).'@dashboard.test', 'password' => 'hashed', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $organization, 'site_name' => "$code Site", 'site_code' => "$code-SITE", 'province' => 'Davao del Sur', 'city_municipality' => 'Davao City', 'environment_type' => 'mangrove', 'status' => 'active', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => "$code-MISSION", 'mission_title' => "$code Mission", 'mission_objective' => 'Exercise dashboard metrics.', 'mission_status' => 'completed', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $deleted ? $now : null]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $organization, 'drone_name' => "$code Drone", 'status' => 'available', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $user, 'flight_code' => "$code-FLIGHT", 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => $now, 'updated_at' => $now]);

        return compact('organization', 'user', 'site', 'mission', 'drone', 'flight');
    }

    private function species(string $scientificName): string
    {
        $id = (string) Str::uuid();
        DB::table('mangrove_species')->insert(['species_id' => $id, 'scientific_name' => $scientificName, 'is_active' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $id;
    }

    /** @param array{mission: string, flight: string} $graph */
    private function tree(array $graph, string $code, ?string $species, string $status, bool $deleted = false): void
    {
        DB::table('tree_observations')->insert([
            'tree_observation_id' => (string) Str::uuid(),
            'mission_id' => $graph['mission'],
            'flight_session_id' => $graph['flight'],
            'tree_code' => $code,
            'tree_location' => $this->point(),
            'final_species_id' => $species,
            'validation_status' => $status,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
            'deleted_at' => $deleted ? now('UTC') : null,
        ]);
    }

    /** @param array{mission: string, site: string, user: string} $graph */
    private function validationSession(array $graph, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $id,
            'mission_id' => $graph['mission'],
            'site_id' => $graph['site'],
            'validated_by' => $graph['user'],
            'validation_date' => '2026-08-24',
            'method' => 'ground_survey',
            'status' => $status,
            'completed_at' => $status === 'completed' ? now('UTC') : null,
            'completed_by' => $status === 'completed' ? $graph['user'] : null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $id;
    }

    private function groundTruth(string $session, ?string $species): void
    {
        DB::table('ground_truth_tree_records')->insert([
            'ground_truth_id' => (string) Str::uuid(),
            'validation_session_id' => $session,
            'species_id' => $species,
            'ground_location' => $this->point(),
            'health_status' => 'healthy',
            'created_at' => now('UTC'),
        ]);
    }

    private function processingJob(string $mission, string $status): void
    {
        DB::table('processing_jobs')->insert([
            'processing_job_id' => (string) Str::uuid(),
            'mission_id' => $mission,
            'job_type' => 'full_pipeline',
            'job_status' => $status,
            'started_at' => $status === 'queued' ? null : now('UTC')->subMinute(),
            'completed_at' => in_array($status, ['completed', 'failed'], true) ? now('UTC') : null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function accuracy(string $id, string $mission, string $type, string $value, string $computedAt): void
    {
        DB::table('accuracy_metrics')->insert([
            'accuracy_metric_id' => $id,
            'mission_id' => $mission,
            'metric_type' => $type,
            'metric_value' => $value,
            'sample_size' => 10,
            'computed_at' => $computedAt,
        ]);
    }

    private function point(): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::raw('ST_SetSRID(ST_MakePoint(125.60, 7.10), 4326)');
        }

        return json_encode(['type' => 'Point', 'coordinates' => [125.60, 7.10]], JSON_THROW_ON_ERROR);
    }
}
