<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiInferenceClient;
use App\Exceptions\DownstreamServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpAiInferenceClient implements AiInferenceClient
{
    private const MODEL_TYPES = [
        'species_classifier',
        'tree_detector',
        'height_estimator',
        'age_estimator',
    ];

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

    /**
     * @return array{
     *     capabilities: array<string, bool|string|int|float|null>,
     *     models: list<array{
     *         key: string,
     *         name: string,
     *         type: string,
     *         framework: string|null,
     *         description: string|null,
     *         versions: list<array{
     *             label: string,
     *             artifact_ref: string,
     *             accuracy: float|null,
     *             precision_score: float|null,
     *             recall_score: float|null,
     *             f1_score: float|null,
     *             rmse: float|null,
     *             release_notes: string|null
     *         }>
     *     }>
     * }
     */
    public function models(string $baseUrl, string $apiKey): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->connectTimeout((int) config('mangroscan.ai_services.connect_timeout_seconds'))
                ->timeout((int) config('mangroscan.ai_services.timeout_seconds'))
                ->get(rtrim($baseUrl, '/').'/models');
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

        return $this->normalizeModelsPayload($response->json());
    }

    /**
     * @return array{
     *     capabilities: array<string, bool|string|int|float|null>,
     *     models: list<array{
     *         key: string,
     *         name: string,
     *         type: string,
     *         framework: string|null,
     *         description: string|null,
     *         versions: list<array{
     *             label: string,
     *             artifact_ref: string,
     *             accuracy: float|null,
     *             precision_score: float|null,
     *             recall_score: float|null,
     *             f1_score: float|null,
     *             rmse: float|null,
     *             release_notes: string|null
     *         }>
     *     }>
     * }
     */
    private function normalizeModelsPayload(mixed $payload): array
    {
        if (! is_array($payload)
            || ! isset($payload['capabilities'], $payload['models'])
            || ! is_array($payload['capabilities'])
            || ! is_array($payload['models'])
            || ! array_is_list($payload['models'])
            || count($payload['models']) > 100) {
            $this->invalidModelsPayload();
        }

        $capabilities = [];
        foreach ($payload['capabilities'] as $key => $value) {
            if (! is_string($key)
                || trim($key) === ''
                || strlen($key) > 100
                || (! is_scalar($value) && $value !== null)) {
                $this->invalidModelsPayload();
            }
            $capabilities[trim($key)] = $value;
        }

        $models = [];
        $modelKeys = [];
        foreach ($payload['models'] as $model) {
            if (! is_array($model)
                || ! is_array($model['versions'] ?? null)
                || ! array_is_list($model['versions'])
                || count($model['versions']) > 100) {
                $this->invalidModelsPayload();
            }

            $key = $this->requiredString($model, 'key', 150);
            $name = $this->requiredString($model, 'name', 150);
            $type = $this->requiredString($model, 'type', 80);
            if (! in_array($type, self::MODEL_TYPES, true) || isset($modelKeys[$key])) {
                $this->invalidModelsPayload();
            }
            $modelKeys[$key] = true;

            $versions = [];
            $versionLabels = [];
            foreach ($model['versions'] as $version) {
                if (! is_array($version)) {
                    $this->invalidModelsPayload();
                }
                $label = $this->requiredString($version, 'label', 80);
                if (isset($versionLabels[$label])) {
                    $this->invalidModelsPayload();
                }
                $versionLabels[$label] = true;
                $versions[] = [
                    'label' => $label,
                    'artifact_ref' => $this->requiredString($version, 'artifact_ref', 2048),
                    'accuracy' => $this->metric($version, 'accuracy', 1),
                    'precision_score' => $this->metric($version, 'precision_score', 1),
                    'recall_score' => $this->metric($version, 'recall_score', 1),
                    'f1_score' => $this->metric($version, 'f1_score', 1),
                    'rmse' => $this->metric($version, 'rmse', 999999),
                    'release_notes' => $this->nullableString($version, 'release_notes', 5000),
                ];
            }

            $models[] = [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'framework' => $this->nullableString($model, 'framework', 80),
                'description' => $this->nullableString($model, 'description', 5000),
                'versions' => $versions,
            ];
        }

        return ['capabilities' => $capabilities, 'models' => $models];
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, int $max): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            $this->invalidModelsPayload();
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key, int $max): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen(trim($value)) > $max) {
            $this->invalidModelsPayload();
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private function metric(array $values, string $key, float $max): ?float
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value)) {
            $this->invalidModelsPayload();
        }
        $number = (float) $value;
        if (! is_finite($number) || $number < 0 || $number > $max) {
            $this->invalidModelsPayload();
        }

        return $number;
    }

    private function invalidModelsPayload(): never
    {
        throw new DownstreamServiceException(
            'The AI service returned invalid model metadata.',
            502,
            'BAD_GATEWAY',
        );
    }
}
