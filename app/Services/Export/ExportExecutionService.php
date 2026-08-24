<?php

namespace App\Services\Export;

use App\Models\ExportedFile;
use App\Models\ExportJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ExportExecutionService
{
    public function __construct(
        private readonly CanonicalTreeExportRenderer $renderer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(string $jobId): void
    {
        $job = DB::transaction(function () use ($jobId): ?ExportJob {
            $job = ExportJob::query()->lockForUpdate()->findOrFail($jobId);
            if (in_array($job->job_status, ['completed', 'running'], true)) {
                return null;
            }
            $job->update(['job_status' => 'running', 'started_at' => now('UTC'), 'error_message' => null]);

            return $job->refresh();
        });
        if ($job === null) {
            return;
        }

        $storageKey = null;
        try {
            $rendered = $this->renderer->render($job->mission_id, $job->export_type, $job->filters ?? []);
            $fileName = 'mangroscan-'.$job->mission_id.'-'.$job->export_job_id.'.'.$rendered['extension'];
            $storageKey = 'exports/'.$job->organization_id.'/'.$job->report_id.'/'.$fileName;
            $disk = Storage::disk(config('mangroscan.media.disk', 'local'));
            if (! $disk->put($storageKey, $rendered['bytes'])) {
                throw new \RuntimeException('The export could not be written to private storage.');
            }

            DB::transaction(function () use ($job, $fileName, $storageKey, $rendered): void {
                $current = ExportJob::query()->lockForUpdate()->findOrFail($job->export_job_id);
                if ($current->job_status !== 'running') {
                    throw new \RuntimeException('The export job is no longer running.');
                }
                $file = ExportedFile::query()->create([
                    'report_id' => $job->report_id, 'mission_id' => $job->mission_id,
                    'export_type' => $job->export_type, 'file_name' => $fileName, 'file_path' => $storageKey,
                    'file_size_bytes' => strlen($rendered['bytes']), 'exported_by' => $job->created_by,
                    'exported_at' => now('UTC'),
                ]);
                $current->update(['job_status' => 'completed', 'exported_file_id' => $file->export_file_id, 'completed_at' => now('UTC'), 'error_message' => null]);
                $this->auditLogger->record(
                    action: 'export.generate.complete', tableName: 'exported_files', recordId: $file->export_file_id,
                    userId: $job->created_by, oldValues: null,
                    newValues: ['export_job_id' => $job->export_job_id, 'report_id' => $job->report_id, 'mission_id' => $job->mission_id, 'export_type' => $job->export_type, 'file_name' => $fileName, 'file_size_bytes' => strlen($rendered['bytes'])],
                    ipAddress: null, userAgent: 'queue:canonical-export', requestId: null,
                );
            });
        } catch (Throwable $exception) {
            if ($storageKey !== null) {
                Storage::disk(config('mangroscan.media.disk', 'local'))->delete($storageKey);
            }
            ExportJob::query()->where('export_job_id', $jobId)->update([
                'job_status' => 'failed', 'exported_file_id' => null, 'completed_at' => null,
                'error_message' => Str::limit($exception->getMessage(), 5000, ''), 'updated_at' => now('UTC'),
            ]);
            throw $exception;
        }
    }
}
