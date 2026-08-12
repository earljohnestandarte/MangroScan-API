<?php

namespace App\Contracts\Ai;

interface AiInferenceClient
{
    /** @return array{status: string, version: string, latency_ms: int} */
    public function health(string $baseUrl, string $apiKey): array;

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
    public function models(string $baseUrl, string $apiKey): array;
}
