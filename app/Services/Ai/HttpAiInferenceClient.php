<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiInferenceClient;
use App\Exceptions\DownstreamServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpAiInferenceClient implements AiInferenceClient
{
    /** @return array{status: string, version: string, latency_ms: int} */
    public function health(string $baseUrl, string $apiKey): array
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->connectTimeout((int) config('mangroscan.ai_services.connect_timeout_seconds'))
                ->timeout((int) config('mangroscan.ai_services.timeout_seconds'))
                ->get(rtrim($baseUrl, '/').'/health');
        } catch (ConnectionException) {
            throw new DownstreamServiceException(
                'The AI service is unavailable.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        } catch (Throwable) {
            throw new DownstreamServiceException(
                'The AI service request failed.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        if (! $response->successful()) {
            throw new DownstreamServiceException(
                'The AI service returned an unsuccessful response.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        $status = $response->json('status');
        $version = $response->json('version');
        if (! is_string($status)
            || ! in_array(strtolower($status), ['ok', 'healthy'], true)
            || ! is_string($version)
            || trim($version) === '') {
            throw new DownstreamServiceException(
                'The AI service returned an invalid health response.',
                502,
                'BAD_GATEWAY',
            );
        }

        return [
            'status' => 'healthy',
            'version' => trim($version),
            'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
        ];
    }
}
