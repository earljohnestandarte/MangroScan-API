<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\GroundTruthStoreRequest;
use App\Http\Resources\GroundTruthTreeRecordResource;
use App\Models\User;
use App\Services\Validation\GroundTruthCreationService;
use App\Services\Validation\ScopedValidationSessionService;
use Illuminate\Http\JsonResponse;

class GroundTruthStoreController extends Controller
{
    // [GT-01] Persist tenant-scoped field evidence in an open validation session.
    public function __invoke(
        GroundTruthStoreRequest $request,
        string $session,
        ScopedValidationSessionService $sessions,
        GroundTruthCreationService $records,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $record = $records->create(
            $sessions->find($actor, $session),
            $actor,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new GroundTruthTreeRecordResource($record))->resolve($request),
        ], 201);
    }
}
