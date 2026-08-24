<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ValidationSessionCompleteRequest;
use App\Http\Resources\ValidationSessionResource;
use App\Models\User;
use App\Services\Validation\ScopedValidationSessionService;
use App\Services\Validation\ValidationSessionCompletionService;
use Illuminate\Http\JsonResponse;

class ValidationSessionCompleteController extends Controller
{
    // [VAL-05] Complete an open session only after all six metrics are fresh.
    public function __invoke(
        ValidationSessionCompleteRequest $request,
        string $session,
        ScopedValidationSessionService $sessions,
        ValidationSessionCompletionService $completion,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $completed = $completion->complete(
            $sessions->find($actor, $session), $actor, $request->validated('notes'),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json(['data' => (new ValidationSessionResource($completed))->resolve($request)]);
    }
}
