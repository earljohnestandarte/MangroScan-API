<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        string $tableName,
        ?string $recordId,
        ?string $userId,
        ?array $oldValues,
        ?array $newValues,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): AuditLog {
        return AuditLog::query()->create([
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'user_id' => $userId,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|api[_-]?key/i', (string) $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }
}
