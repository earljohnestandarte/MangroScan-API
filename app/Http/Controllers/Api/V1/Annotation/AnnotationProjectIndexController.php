<?php

namespace App\Http\Controllers\Api\V1\Annotation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Annotation\AnnotationProjectIndexRequest;
use App\Http\Resources\AnnotationProjectResource;
use App\Models\AnnotationProject;
use App\Models\User;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Http\JsonResponse;

class AnnotationProjectIndexController extends Controller
{
    // [ANN-01] List annotation projects in the caller's organization scope.
    public function __invoke(AnnotationProjectIndexRequest $request, EffectiveAccessService $effectiveAccess): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $globalScope = in_array('organizations.manage', $effectiveAccess->rolesAndPermissions($actor)['permissions'], true);
        $query = AnnotationProject::query();
        if (! $globalScope) {
            $query->where('organization_id', $actor->organization_id);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $projects = $query->orderByDesc('created_at')->orderBy('annotation_project_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => AnnotationProjectResource::collection(collect($projects->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
                'last_page' => $projects->lastPage(),
            ],
        ]);
    }
}
