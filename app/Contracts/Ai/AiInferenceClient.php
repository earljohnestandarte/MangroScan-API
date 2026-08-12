<?php

namespace App\Contracts\Ai;

interface AiInferenceClient
{
    /** @return array{status: string, version: string, latency_ms: int} */
    public function health(string $baseUrl, string $apiKey): array;
}
