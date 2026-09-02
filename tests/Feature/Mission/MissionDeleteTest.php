<?php

namespace Tests\Feature\Mission;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class MissionDeleteTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_soft_archives_a_planned_mission_with_audit(): void
    {
        $identity = $this->apiIdentity(['missions.delete'], 'mission-delete-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'DELETE');
        DB::table('survey_missions')->where('mission_id', $lineage['mission_id'])
            ->update(['mission_status' => 'planned']);

        $this->withToken($identity['token'])
            ->withHeader('X-Request-ID', 'req_msn_05')
            ->deleteJson('/api/v1/missions/'.$lineage['mission_id'])
            ->assertNoContent();

        $this->assertSoftDeleted('survey_missions', ['mission_id' => $lineage['mission_id']]);
        $audit = AuditLog::query()->sole();
        $this->assertSame('mission.delete', $audit->action);
        $this->assertSame('req_msn_05', $audit->request_id);
    }

    public function test_it_rejects_non_planned_and_foreign_missions(): void
    {
        $identity = $this->apiIdentity(['missions.delete'], 'mission-delete-scope-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'DELETE-SCOPE');

        $this->withToken($identity['token'])->deleteJson('/api/v1/missions/'.$lineage['mission_id'])
            ->assertConflict()->assertJsonPath('error.details.current_status', 'completed');

        $foreign = $this->apiIdentity([], 'mission-delete-foreign-');
        $foreignLineage = $this->missionLineage($foreign['organization_id'], $foreign['actor_id'], 'DELETE-FOREIGN');
        DB::table('survey_missions')->where('mission_id', $foreignLineage['mission_id'])
            ->update(['mission_status' => 'planned']);
        $this->withToken($identity['token'])->deleteJson('/api/v1/missions/'.$foreignLineage['mission_id'])
            ->assertNotFound();
    }

    public function test_it_enforces_access_and_rolls_back_audit_failure(): void
    {
        $identity = $this->apiIdentity(['missions.delete'], 'mission-delete-rollback-');
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], 'DELETE-ROLLBACK');
        DB::table('survey_missions')->where('mission_id', $lineage['mission_id'])
            ->update(['mission_status' => 'planned']);

        $this->deleteJson('/api/v1/missions/'.$lineage['mission_id'])->assertUnauthorized();
        $denied = $this->apiIdentity([], 'mission-delete-denied-');
        $deniedLineage = $this->missionLineage($denied['organization_id'], $denied['actor_id'], 'DELETE-DENIED');
        DB::table('survey_missions')->where('mission_id', $deniedLineage['mission_id'])
            ->update(['mission_status' => 'planned']);
        $this->withToken($denied['token'])
            ->deleteJson('/api/v1/missions/'.$deniedLineage['mission_id'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->withToken($identity['token'])->deleteJson('/api/v1/missions/'.$lineage['mission_id'])
            ->assertInternalServerError();
        $this->assertDatabaseHas('survey_missions', [
            'mission_id' => $lineage['mission_id'],
            'deleted_at' => null,
        ]);
    }
}
