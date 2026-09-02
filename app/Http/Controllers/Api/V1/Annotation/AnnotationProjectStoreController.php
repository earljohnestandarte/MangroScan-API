<?php

namespace App\Http\Controllers\Api\V1\Annotation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Annotation\AnnotationProjectStoreRequest;
use App\Http\Resources\AnnotationProjectResource;
use App\Models\User;
use App\Services\Annotation\AnnotationProjectCreationService;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Http\JsonResponse;

class AnnotationProjectStoreController extends Controller
{
    // [ANN-02] Create an organization-owned annotation project.
    public function __invoke(
        AnnotationProjectStoreRequest $request,
        AnnotationProjectCreationService $projects,
        EffectiveAccessService $effectiveAccess,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $globalScope = in_array('organizations.manage', $effectiveAccess->rolesAndPermissions($actor)['permissions'], true);
        $project = $projects->create(
            $actor, $request->validated(), $globalScope,
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new AnnotationProjectResource($project))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
