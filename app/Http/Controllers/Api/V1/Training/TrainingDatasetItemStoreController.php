<?php

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingDatasetItemStoreRequest;
use App\Http\Resources\TrainingDatasetItemResource;
use App\Models\TrainingDataset;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use App\Services\Training\TrainingDatasetItemCreationService;
use Illuminate\Http\JsonResponse;

class TrainingDatasetItemStoreController extends Controller
{
    // [DATASET-03] Attach a labeled media/sample record to a training dataset.
    public function __invoke(
        TrainingDatasetItemStoreRequest $request,
        string $dataset,
        TrainingDatasetItemCreationService $items,
        EffectiveAccessService $effectiveAccess,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = TrainingDataset::query()->findOrFail($dataset);
        $globalScope = in_array(
            'organizations.manage',
            $effectiveAccess->rolesAndPermissions($actor)['permissions'],
            true,
        );
        $item = $items->create(
            $target, $actor, $request->validated(), $globalScope,
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new TrainingDatasetItemResource($item))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
