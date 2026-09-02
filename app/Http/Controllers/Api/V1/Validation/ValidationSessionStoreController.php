<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ValidationSessionStoreRequest;
use App\Http\Resources\ValidationSessionResource;
use App\Models\User;
use App\Services\Validation\ValidationSessionCreationService;
use Illuminate\Http\JsonResponse;

class ValidationSessionStoreController extends Controller
{
    // [VAL-03] Create a tenant-safe validation activity and mandatory audit evidence.
    public function __invoke(
        ValidationSessionStoreRequest $request,
        ValidationSessionCreationService $sessions,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $session = $sessions->create(
            $actor,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ValidationSessionResource($session))->resolve($request),
        ], 201);
    }
}
