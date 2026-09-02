<?php

namespace App\Services\Export;

use App\Contracts\Export\PrivateDownloadUrlIssuer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ExportDownloadService
{
    public function __construct(
        private readonly ScopedExportedFileService $files,
        private readonly PrivateDownloadUrlIssuer $issuer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array{url:string,expires_at:string} */
    public function issue(User $actor, string $id, ?string $ip, ?string $agent, ?string $requestId): array
    {
        $file = $this->files->find($actor, $id);
        $expiresAt = now('UTC')->addMinutes(max(1, (int) config('mangroscan.exports.download_url_ttl_minutes')));

        return DB::transaction(function () use ($actor, $file, $expiresAt, $ip, $agent, $requestId): array {
            $this->auditLogger->record(
                action: 'export.download.issue', tableName: 'exported_files', recordId: $file->export_file_id,
                userId: $actor->user_id, oldValues: null,
                newValues: ['export_type' => $file->export_type, 'expires_at' => $expiresAt->toIso8601String()],
                ipAddress: $ip, userAgent: $agent, requestId: $requestId,
            );
            $url = $this->issuer->issue(
                (string) config('mangroscan.exports.disk'), $file->file_path, $expiresAt,
            );

            return ['url' => $url, 'expires_at' => $expiresAt->toIso8601String()];
        });
    }
}
