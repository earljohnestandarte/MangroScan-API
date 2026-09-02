<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ConfidenceFlagUpdateRequest;
use App\Http\Resources\ConfidenceFlagResource;
use App\Models\User;
use App\Services\Validation\ConfidenceFlagUpdateService;
use Illuminate\Http\JsonResponse;

class ConfidenceFlagUpdateController extends Controller
{
    public function __invoke(
        ConfidenceFlagUpdateRequest $request,
        string $result,
        ConfidenceFlagUpdateService $flags,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $flag = $flags->update(
            $result, $actor, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json(['data' => (new ConfidenceFlagResource($flag))->resolve($request)]);
    }
}
