<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ConfidenceReviewIndexRequest;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use App\Services\Validation\ConfidenceReviewService;
use Illuminate\Http\JsonResponse;

class ConfidenceReviewIndexController extends Controller
{
    public function __invoke(
        ConfidenceReviewIndexRequest $request,
        ScopedMissionService $missions,
        ConfidenceReviewService $reviews,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $mission = $missions->find($actor, $validated['mission_id']);

        return response()->json($reviews->list($mission, $validated));
    }
}
