<?php

namespace App\Jobs;

use App\Services\Ai\AiProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiProcessing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public readonly string $jobId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiProcessingService $processing): void
    {
        $processing->execute($this->jobId);
    }
}
