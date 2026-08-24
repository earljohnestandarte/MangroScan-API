<?php

namespace Tests\Feature\Database;

use App\Models\AccuracyMetric;
use App\Models\GroundTruthTreeRecord;
use App\Models\ValidationMatch;
use App\Models\ValidationSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidationDatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    // [VAL-DB] The authoritative validation schema includes the approved completion and metric lineage extensions.
    public function test_it_provisions_the_documented_base_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('validation_sessions', [
            'validation_session_id', 'mission_id', 'site_id', 'plot_id', 'validated_by',
            'validation_date', 'method', 'status', 'notes', 'completed_at', 'completed_by',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ground_truth_tree_records', [
            'ground_truth_id', 'validation_session_id', 'species_id', 'ground_location',
            'measured_height_meters', 'estimated_age_years', 'diameter_cm', 'health_status',
            'photo_path', 'remarks', 'created_at',
        ]));
        foreach (['field_code', 'crown_diameter_m', 'is_tree'] as $unapprovedColumn) {
            $this->assertFalse(Schema::hasColumn('ground_truth_tree_records', $unapprovedColumn));
        }

        $this->assertTrue(Schema::hasColumns('validation_matches', [
            'validation_match_id', 'ground_truth_id', 'tree_observation_id', 'match_status',
            'distance_error_meters', 'species_correct', 'height_error_meters', 'age_error_years',
            'validated_by', 'validated_at',
        ]));
        foreach (['accepted_species_id', 'accepted_height_m', 'accepted_age_years', 'corrected_geometry', 'validation_evidence'] as $unapprovedColumn) {
            $this->assertFalse(Schema::hasColumn('validation_matches', $unapprovedColumn));
        }

        $this->assertTrue(Schema::hasColumns('accuracy_metrics', [
            'accuracy_metric_id', 'validation_session_id', 'mission_id', 'model_version_id', 'metric_type',
            'metric_value', 'sample_size', 'computed_at', 'notes',
        ]));
        $this->assertTrue(Schema::hasColumns('confidence_flags', [
            'confidence_flag_id', 'mission_id', 'result_id', 'result_type', 'status', 'severity',
            'review_note', 'assigned_to', 'reason', 'resolution_notes', 'created_by', 'created_at', 'updated_at',
        ]));
    }

    // [VAL-DB] UUID models, documented relations and cascade ownership behave consistently.
    public function test_it_persists_relationships_and_cascades_session_owned_evidence(): void
    {
        $graph = $this->graph();

        $session = ValidationSession::query()->create([
            'mission_id' => $graph['mission_id'],
            'site_id' => $graph['site_id'],
            'plot_id' => $graph['plot_id'],
            'validated_by' => $graph['user_id'],
            'validation_date' => '2026-08-13',
            'method' => 'ground_survey',
            'notes' => 'Baseline field validation.',
        ]);
        $groundTruthId = (string) Str::uuid();
        DB::table('ground_truth_tree_records')->insert([
            'ground_truth_id' => $groundTruthId,
            'validation_session_id' => $session->validation_session_id,
            'species_id' => $graph['species_id'],
            'ground_location' => DB::getDriverName() === 'pgsql'
                ? DB::raw('ST_SetSRID(ST_MakePoint(123.305278, 9.306944), 4326)')
                : json_encode(['type' => 'Point', 'coordinates' => [123.305278, 9.306944]], JSON_THROW_ON_ERROR),
            'measured_height_meters' => '4.25',
            'estimated_age_years' => '6.50',
            'diameter_cm' => '18.75',
            'health_status' => 'healthy',
            'remarks' => 'Verified in field.',
            'created_at' => now(),
        ]);

        $match = ValidationMatch::query()->create([
            'ground_truth_id' => $groundTruthId,
            'tree_observation_id' => null,
            'match_status' => 'false_negative',
            'validated_by' => $graph['user_id'],
            'validated_at' => now(),
        ]);
        $metric = AccuracyMetric::query()->create([
            'mission_id' => $graph['mission_id'],
            'metric_type' => 'count_precision',
            'metric_value' => '0.875000',
            'sample_size' => 16,
            'computed_at' => now(),
            'notes' => 'Mission-scoped baseline metric.',
        ]);

        $session->load(['mission', 'site', 'plot', 'validator', 'groundTruthRecords.species']);
        $groundTruth = GroundTruthTreeRecord::query()->findOrFail($groundTruthId);
        $match->load(['groundTruthRecord', 'validator']);
        $metric->load('mission');

        $this->assertTrue(Str::isUuid($session->validation_session_id));
        $this->assertSame($graph['mission_id'], $session->mission->mission_id);
        $this->assertSame($graph['site_id'], $session->site->site_id);
        $this->assertSame($graph['plot_id'], $session->plot->plot_id);
        $this->assertSame($graph['user_id'], $session->validator->user_id);
        $this->assertSame($groundTruthId, $session->groundTruthRecords->sole()->ground_truth_id);
        $this->assertSame($graph['species_id'], $groundTruth->species->species_id);
        $this->assertSame($groundTruthId, $match->groundTruthRecord->ground_truth_id);
        $this->assertNull($match->treeObservation);
        $this->assertSame($graph['mission_id'], $metric->mission->mission_id);

        DB::table('validation_sessions')
            ->where('validation_session_id', $session->validation_session_id)
            ->delete();

        $this->assertDatabaseMissing('ground_truth_tree_records', ['ground_truth_id' => $groundTruthId]);
        $this->assertDatabaseMissing('validation_matches', ['validation_match_id' => $match->validation_match_id]);
        $this->assertDatabaseHas('accuracy_metrics', ['accuracy_metric_id' => $metric->accuracy_metric_id]);
    }

    // [VAL-DB] PostgreSQL uses a genuine Point(4326) and enforces documented domains.
    public function test_postgresql_enforces_spatial_and_domain_invariants(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL/PostGIS validation-foundation verification.');
        }

        $graph = $this->graph();
        $sessionId = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $sessionId,
            'mission_id' => $graph['mission_id'],
            'site_id' => $graph['site_id'],
            'plot_id' => $graph['plot_id'],
            'validated_by' => $graph['user_id'],
            'validation_date' => '2026-08-13',
            'method' => 'expert_review',
        ]);
        $groundTruthId = (string) Str::uuid();
        DB::table('ground_truth_tree_records')->insert([
            'ground_truth_id' => $groundTruthId,
            'validation_session_id' => $sessionId,
            'ground_location' => DB::raw('ST_SetSRID(ST_MakePoint(123.305278, 9.306944), 4326)'),
            'health_status' => 'unknown',
        ]);

        $geometry = DB::table('ground_truth_tree_records')
            ->where('ground_truth_id', $groundTruthId)
            ->selectRaw('GeometryType(ground_location) AS geometry_type, ST_SRID(ground_location) AS srid')
            ->first();
        $this->assertSame('POINT', $geometry->geometry_type);
        $this->assertSame(4326, $geometry->srid);

        $this->expectException(QueryException::class);
        DB::table('accuracy_metrics')->insert([
            'accuracy_metric_id' => (string) Str::uuid(),
            'mission_id' => $graph['mission_id'],
            'metric_type' => 'unsupported_metric',
            'metric_value' => -1,
        ]);
    }

    // [VAL-DB] The foundation remains closed while endpoint-specific grants and routes are additive.
    public function test_it_versions_a_closed_foundation_dcl_and_the_validation_scope_route(): void
    {
        $dcl = file_get_contents(database_path('sql/dcl/045_validation_foundation_grants.sql'));
        $migration = file_get_contents(database_path('migrations/2026_08_12_066000_create_validation_foundation_tables.php'));

        $this->assertIsString($dcl);
        $this->assertStringContainsString('REVOKE ALL PRIVILEGES ON TABLE', $dcl);
        foreach (['validation_sessions', 'ground_truth_tree_records', 'validation_matches', 'accuracy_metrics'] as $table) {
            $this->assertStringContainsString('app.'.$table, $dcl);
        }
        foreach (['PUBLIC', 'mangroscan_api_rw', 'mangroscan_worker', 'mangroscan_report_ro', 'mangroscan_auditor'] as $role) {
            $this->assertStringContainsString($role, $dcl);
        }
        foreach (['GRANT SELECT', 'GRANT INSERT', 'GRANT UPDATE', 'GRANT DELETE'] as $grant) {
            $this->assertStringNotContainsString($grant, $dcl);
        }

        $this->assertIsString($migration);
        foreach ([
            'validation_sessions_method_check',
            'ground_truth_tree_records_health_status_check',
            'ground_truth_tree_records_measurements_check',
            'validation_matches_status_check',
            'validation_matches_error_values_check',
            'accuracy_metrics_type_check',
            'accuracy_metrics_value_check',
            "->spatialIndex('ground_location')",
        ] as $invariant) {
            $this->assertStringContainsString($invariant, $migration);
        }

        $this->assertTrue(collect(Route::getRoutes())->contains(
            fn ($route): bool => $route->uri() === 'api/v1/validation/scopes',
        ));
    }

    /** @return array{organization_id:string,user_id:string,site_id:string,mission_id:string,plot_id:string,species_id:string} */
    private function graph(): array
    {
        $organizationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $siteId = (string) Str::uuid();
        $missionId = (string) Str::uuid();
        $plotId = (string) Str::uuid();
        $speciesId = (string) Str::uuid();
        $suffix = Str::upper(Str::random(8));

        DB::table('organizations')->insert([
            'organization_id' => $organizationId,
            'organization_name' => 'Validation Foundation '.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'first_name' => 'Field',
            'last_name' => 'Validator',
            'email' => 'validator-'.Str::lower($suffix).'@example.test',
            'password' => 'not-used-in-foundation-test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('survey_sites')->insert([
            'site_id' => $siteId,
            'organization_id' => $organizationId,
            'site_name' => 'Validation Site '.$suffix,
            'site_code' => 'VAL-'.$suffix,
            'province' => 'Negros Oriental',
            'city_municipality' => 'Dumaguete City',
            'environment_type' => 'estuarine',
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('survey_missions')->insert([
            'mission_id' => $missionId,
            'site_id' => $siteId,
            'mission_code' => 'VAL-MSN-'.$suffix,
            'mission_title' => 'Validation Mission '.$suffix,
            'mission_objective' => 'Verify field validation database relationships.',
            'mission_status' => 'completed',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monitoring_plots')->insert([
            'plot_id' => $plotId,
            'site_id' => $siteId,
            'plot_code' => 'VAL-PLOT-'.$suffix,
            'plot_name' => 'Validation Plot '.$suffix,
            'plot_geom' => DB::getDriverName() === 'pgsql'
                ? DB::raw("ST_SetSRID(ST_GeomFromText('POLYGON((123.305 9.306,123.306 9.306,123.306 9.307,123.305 9.306))'),4326)")
                : json_encode([
                    'type' => 'Polygon',
                    'coordinates' => [[[123.305, 9.306], [123.306, 9.306], [123.306, 9.307], [123.305, 9.306]]],
                ], JSON_THROW_ON_ERROR),
            'area_square_meters' => '100.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('mangrove_species')->insert([
            'species_id' => $speciesId,
            'scientific_name' => 'Rhizophora test '.$suffix,
            'common_name' => 'Test mangrove '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'site_id' => $siteId,
            'mission_id' => $missionId,
            'plot_id' => $plotId,
            'species_id' => $speciesId,
        ];
    }
}
