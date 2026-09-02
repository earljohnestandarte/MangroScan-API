<?php

namespace App\Services\Flight;

use App\Exceptions\WorkflowConflictException;
use App\Models\FlightChecklist;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class FlightChecklistSubmissionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function submit(
        User $actor,
        FlightSession $flight,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): FlightChecklist {
        return DB::transaction(function () use (
            $actor,
            $flight,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): FlightChecklist {
            $current = FlightSession::query()
                ->lockForUpdate()
                ->findOrFail($flight->flight_session_id);

            $this->assertLifecycle($current, $data['checklist_type']);

            $checklist = FlightChecklist::query()->create([
                'flight_session_id' => $current->flight_session_id,
                'checked_by' => $actor->user_id,
                'checklist_type' => $data['checklist_type'],
                'battery_ok' => $data['battery_ok'],
                'weather_ok' => $data['weather_ok'],
                'gps_ok' => $data['gps_ok'],
                'camera_ok' => $data['camera_ok'],
                'lidar_depth_ok' => $data['lidar_depth_ok'],
                'storage_ok' => $data['storage_ok'],
                'overall_status' => $data['overall_status'],
                'remarks' => $data['remarks'] ?? null,
                'created_at' => now(),
            ]);

            DB::table('flight_sessions')
                ->where('flight_session_id', $current->flight_session_id)
                ->update([
                    'sync_version' => DB::raw('sync_version + 1'),
                    'updated_at' => now(),
                ]);

            $this->auditLogger->record(
                action: 'flight.checklist.submit',
                tableName: 'flight_checklists',
                recordId: $checklist->checklist_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $checklist->only([
                    'checklist_id',
                    'flight_session_id',
                    'checked_by',
                    'checklist_type',
                    'battery_ok',
                    'weather_ok',
                    'gps_ok',
                    'camera_ok',
                    'lidar_depth_ok',
                    'storage_ok',
                    'overall_status',
                    'remarks',
                    'created_at',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $checklist->refresh();
        });
    }

    private function assertLifecycle(FlightSession $flight, string $type): void
    {
        if ($type === 'pre_flight' && $flight->flight_status === 'planned') {
            return;
        }

        if ($type === 'post_flight' && in_array(
            $flight->flight_status,
            ['completed', 'aborted', 'failed'],
            true,
        )) {
            return;
        }

        throw new WorkflowConflictException(
            'The checklist type is not valid for the current flight state.',
            [
                'checklist_type' => $type,
                'current_status' => $flight->flight_status,
            ],
        );
    }
}
