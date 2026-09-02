<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ValidationDecisionStoreRequest;
use App\Http\Resources\ValidationMatchResource;
use App\Models\User;
use App\Services\Validation\ScopedValidationSessionService;
use App\Services\Validation\ValidationDecisionCreationService;
use Illuminate\Http\JsonResponse;

class ValidationDecisionStoreController extends Controller
{
    // [MATCH-01] Persist a server-calculated validation decision and its canonical tree outcome.
    public function __invoke(
        ValidationDecisionStoreRequest $request,
        string $session,
        ScopedValidationSessionService $sessions,
        ValidationDecisionCreationService $decisions,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $decision = $decisions->create(
            $sessions->find($actor, $session),
            $actor,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ValidationMatchResource($decision))->resolve($request),
        ], 201);
    }
}
