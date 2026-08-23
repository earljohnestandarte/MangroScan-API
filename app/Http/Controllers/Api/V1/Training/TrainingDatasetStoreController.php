<?php

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingDatasetStoreRequest;
use App\Http\Resources\TrainingDatasetResource;
use App\Models\User;
use App\Services\Training\TrainingDatasetCreationService;
use Illuminate\Http\JsonResponse;

class TrainingDatasetStoreController extends Controller
{
    // [DATASET-02] Create auditable metadata for a model-training dataset.
    public function __invoke(TrainingDatasetStoreRequest $request, TrainingDatasetCreationService $datasets): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $dataset = $datasets->create(
            $actor, $request->validated(), $request->ip(), $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new TrainingDatasetResource($dataset))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
