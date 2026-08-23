<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccuracyMetricResource;
use App\Models\User;
use App\Services\Validation\AccuracyRecomputeService;
use App\Services\Validation\ScopedValidationSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccuracyRecomputeController extends Controller
{
    public function __invoke(
        Request $request,
        string $session,
        ScopedValidationSessionService $sessions,
        AccuracyRecomputeService $accuracy,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $metrics = $accuracy->recompute(
            $sessions->find($actor, $session), $actor, $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json(['data' => AccuracyMetricResource::collection($metrics)->resolve($request)]);
    }
}
