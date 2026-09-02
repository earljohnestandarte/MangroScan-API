<?php

namespace App\Services\Flight;

use App\Models\EnvironmentLog;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class EnvironmentLogCreationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        User $actor,
        FlightSession $flight,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): EnvironmentLog {
        return DB::transaction(function () use (
            $actor,
            $flight,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): EnvironmentLog {
            $log = EnvironmentLog::query()->create([
                'flight_session_id' => $flight->flight_session_id,
                'recorded_at' => $data['recorded_at'],
                'weather_condition' => $data['weather_condition'],
                'wind_speed_mps' => $data['wind_speed_mps'] ?? null,
                'temperature_celsius' => $data['temperature_celsius'] ?? null,
                'humidity_percent' => $data['humidity_percent'] ?? null,
                'visibility_status' => $data['visibility_status'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLogger->record(
                action: 'environment_log.create',
                tableName: 'environment_logs',
                recordId: $log->environment_log_id,
                userId: $actor->user_id,
                oldValues: null,
                newValues: $log->only([
                    'flight_session_id',
                    'recorded_at',
                    'weather_condition',
                    'wind_speed_mps',
                    'temperature_celsius',
                    'humidity_percent',
                    'visibility_status',
                    'notes',
                ]),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return $log->refresh();
        });
    }
}
