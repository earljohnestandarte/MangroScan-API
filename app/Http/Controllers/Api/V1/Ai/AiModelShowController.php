<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiModelResource;
use App\Http\Resources\AiModelVersionResource;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiModelShowController extends Controller
{
    // [MODEL-02] Return one global model and its safe version provenance.
    public function __invoke(Request $request, string $model): JsonResponse
    {
        $registryModel = AiModel::query()->findOrFail($model);
        $versions = $registryModel->versions()
            ->orderByDesc('is_deployed')
            ->orderByDesc('created_at')
            ->orderByDesc('model_version_id')
            ->get();

        return response()->json([
            'data' => [
                'model' => (new AiModelResource($registryModel))->resolve($request),
                'versions' => AiModelVersionResource::collection($versions)->resolve($request),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
