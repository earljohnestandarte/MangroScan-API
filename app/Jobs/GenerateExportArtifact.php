<?php

namespace App\Jobs;

use App\Services\Export\ExportExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExportArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $jobId)
    {
        $this->onQueue('exports');
    }

    public function handle(ExportExecutionService $exports): void
    {
        $exports->execute($this->jobId);
    }
}
