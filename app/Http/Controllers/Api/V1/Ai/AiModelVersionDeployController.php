<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiModelVersionDeployRequest;
use App\Http\Resources\AiModelVersionResource;
use App\Models\AiModel;
use App\Models\User;
use App\Services\Ai\AiModelVersionDeploymentService;
use Illuminate\Http\JsonResponse;

class AiModelVersionDeployController extends Controller
{
    // [MODEL-03] Atomically make one validated version the deployed version for its model.
    public function __invoke(
        AiModelVersionDeployRequest $request,
        string $model,
        string $version,
        AiModelVersionDeploymentService $deployment,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $registryModel = AiModel::query()->findOrFail($model);
        $target = $registryModel->versions()->findOrFail($version);
        $deployed = $deployment->deploy(
            $registryModel,
            $target,
            $actor,
            $validated['release_notes'] ?? null,
            array_key_exists('release_notes', $validated),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new AiModelVersionResource($deployed))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
