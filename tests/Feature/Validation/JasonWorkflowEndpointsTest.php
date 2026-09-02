<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class JasonWorkflowEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_layer_build_queues_a_durable_job_and_rejects_an_overlapping_active_build(): void
    {
        $graph = $this->graph(['processing_jobs.manage']);

        $response = $this->withToken($graph['token'])->postJson('/api/v1/missions/'.$graph['mission'].'/layers/build', [
            'layer_types' => [' SPECIES_MAP ', 'tree_points'],
            'parameters' => ['palette' => 'viridis'],
        ]);

        $response->assertAccepted()->assertJsonStructure(['data' => ['job_id']]);
        $jobId = $response->json('data.job_id');
        $job = DB::table('processing_jobs')->where('processing_job_id', $jobId)->first();
        $this->assertSame('photogrammetry', $job->job_type);
        $this->assertSame('queued', $job->job_status);
        $this->assertSame(['species_map', 'tree_points'], json_decode($job->input_summary, true, 512, JSON_THROW_ON_ERROR)['layer_build']['layer_types']);

        $this->withToken($graph['token'])->postJson('/api/v1/missions/'.$graph['mission'].'/layers/build', [
            'layer_types' => ['tree_points'],
        ])->assertConflict();
    }

    public function test_confidence_queue_is_tenant_scoped_and_flags_are_upserted(): void
    {
        $graph = $this->graph(['results.read', 'validation.decide']);

        $this->withToken($graph['token'])->getJson('/api/v1/confidence-review?mission_id='.$graph['mission'])
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.result_id', $graph['tree'])
            ->assertJsonPath('data.0.result_type', 'detection')
            ->assertJsonPath('data.0.severity', 'critical')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('map.0.location.type', 'Point');

        $this->withToken($graph['token'])->putJson('/api/v1/confidence-review/'.$graph['tree'], [
            'status' => ' IN_REVIEW ',
            'review_note' => 'Needs field confirmation.',
            'assigned_to' => $graph['actor'],
        ])->assertOk()
            ->assertJsonPath('data.result_type', 'detection')
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonMissingPath('data.confidence_score');

        $this->withToken($graph['token'])->getJson('/api/v1/confidence-review?mission_id='.$graph['mission'].'&status=in_review')
            ->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.review_note', 'Needs field confirmation.');
    }

    public function test_accuracy_recompute_persists_session_metrics_and_completion_enforces_the_protocol_gate(): void
    {
        $graph = $this->graph(['accuracy.recompute', 'validation.complete']);
        $session = (string) Str::uuid();
        DB::table('validation_sessions')->insert([
            'validation_session_id' => $session, 'mission_id' => $graph['mission'], 'site_id' => $graph['site'],
            'validated_by' => $graph['actor'], 'validation_date' => '2026-08-23', 'method' => 'ground_survey',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            ['matched', true, 1.0, 2.0],
            ['false_positive', false, null, null],
            ['false_negative', null, 3.0, 4.0],
        ] as $index => [$status, $speciesCorrect, $heightError, $ageError]) {
            $truth = (string) Str::uuid();
            DB::table('ground_truth_tree_records')->insert([
                'ground_truth_id' => $truth, 'validation_session_id' => $session,
                'ground_location' => json_encode(['type' => 'Point', 'coordinates' => [123.3 + $index / 1000, 9.3]], JSON_THROW_ON_ERROR),
                'health_status' => 'healthy', 'created_at' => now(),
            ]);
            DB::table('validation_matches')->insert([
                'validation_match_id' => (string) Str::uuid(), 'validation_session_id' => $session,
                'ground_truth_id' => $status === 'false_positive' ? null : $truth,
                'tree_observation_id' => $status === 'false_negative' ? null : $graph['tree'], 'match_status' => $status,
                'species_correct' => $speciesCorrect, 'height_error_meters' => $heightError,
                'age_error_years' => $ageError, 'validated_by' => $graph['actor'], 'validated_at' => now(),
            ]);
        }

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions/'.$session.'/complete', ['notes' => 'Premature.'])
            ->assertConflict();
        $metrics = $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions/'.$session.'/accuracy/recompute')
            ->assertOk()->assertJsonCount(6, 'data');
        $byType = collect($metrics->json('data'))->keyBy('metric_type');
        $this->assertSame('0.500000', $byType['count_precision']['metric_value']);
        $this->assertSame('0.500000', $byType['count_recall']['metric_value']);
        $this->assertSame('0.500000', $byType['count_f1']['metric_value']);

        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions/'.$session.'/complete', ['notes' => 'Field review complete.'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.notes', 'Field review complete.');
        $this->withToken($graph['token'])->postJson('/api/v1/validation-sessions/'.$session.'/complete', ['notes' => 'Again.'])
            ->assertConflict();
    }

    /** @param list<string> $permissions */
    private function graph(array $permissions): array
    {
        $org = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $site = (string) Str::uuid();
        $mission = (string) Str::uuid();
        $drone = (string) Str::uuid();
        $flight = (string) Str::uuid();
        $tree = (string) Str::uuid();
        $role = (string) Str::uuid();
        $suffix = Str::lower(Str::random(8));
        DB::table('organizations')->insert(['organization_id' => $org, 'organization_name' => 'Workflow '.$suffix, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['user_id' => $actor, 'organization_id' => $org, 'first_name' => 'Jason', 'last_name' => 'Tester', 'email' => $suffix.'@example.test', 'password' => Hash::make('password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_sites')->insert(['site_id' => $site, 'organization_id' => $org, 'site_name' => 'Site '.$suffix, 'site_code' => 'SITE-'.Str::upper($suffix), 'province' => 'Negros Oriental', 'city_municipality' => 'Dumaguete City', 'environment_type' => 'estuarine', 'status' => 'active', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('survey_missions')->insert(['mission_id' => $mission, 'site_id' => $site, 'mission_code' => 'MSN-'.Str::upper($suffix), 'mission_title' => 'Mission '.$suffix, 'mission_objective' => 'Verify Jason workflow endpoints.', 'mission_status' => 'completed', 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('drones')->insert(['drone_id' => $drone, 'organization_id' => $org, 'drone_name' => 'Drone '.$suffix, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('flight_sessions')->insert(['flight_session_id' => $flight, 'mission_id' => $mission, 'drone_id' => $drone, 'pilot_user_id' => $actor, 'flight_code' => 'FLT-'.Str::upper($suffix), 'flight_status' => 'completed', 'quality_status' => 'acceptable', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tree_observations')->insert(['tree_observation_id' => $tree, 'mission_id' => $mission, 'flight_session_id' => $flight, 'tree_code' => 'TREE-'.Str::upper($suffix), 'tree_location' => json_encode(['type' => 'Point', 'coordinates' => [123.3, 9.3]], JSON_THROW_ON_ERROR), 'detection_confidence' => 0.35, 'validation_status' => 'unvalidated', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('roles')->insert(['role_id' => $role, 'organization_id' => $org, 'role_name' => 'Workflow role '.$suffix, 'role_code' => 'workflow_'.$suffix, 'is_system_role' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $actor, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('permission_code', $permission)->value('permission_id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['permission_id' => $id, 'permission_code' => $permission, 'permission_name' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        /** @var User $user */
        $user = User::query()->findOrFail($actor);

        return compact('org', 'actor', 'site', 'mission', 'flight', 'tree') + ['token' => $user->createToken('workflow')->plainTextToken];
    }
}
