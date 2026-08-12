<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiModelIndexRequest;
use App\Http\Resources\AiModelResource;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;

class AiModelIndexController extends Controller
{
    // [MODEL-01] List the global AI model registry with deployment-aware filters.
    public function __invoke(AiModelIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = AiModel::query();

        if (! empty($validated['type'])) {
            $query->where('model_type', $validated['type']);
        }

        if (array_key_exists('deployed', $validated)) {
            $method = $validated['deployed'] ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}('versions', fn ($query) => $query->where('is_deployed', true));
        }

        $models = $query->orderBy('model_name')->orderBy('model_id')->get();

        return response()->json([
            'data' => AiModelResource::collection($models)->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
