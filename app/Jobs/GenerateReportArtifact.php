<?php

namespace App\Jobs;

use App\Services\Report\ReportGenerationExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $jobId)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportGenerationExecutionService $generation): void
    {
        $generation->execute($this->jobId);
    }
}
