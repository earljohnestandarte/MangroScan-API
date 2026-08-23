<?php

namespace App\Http\Controllers\Api\V1\Annotation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Annotation\AnnotationExportStoreRequest;
use App\Models\AnnotationProject;
use App\Models\User;
use App\Services\Annotation\AnnotationExportCreationService;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Http\JsonResponse;

class AnnotationExportStoreController extends Controller
{
    // [ANN-04] Materialize a private annotation export in a documented format.
    public function __invoke(
        AnnotationExportStoreRequest $request,
        string $project,
        AnnotationExportCreationService $exports,
        EffectiveAccessService $effectiveAccess,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $globalScope = in_array('organizations.manage', $effectiveAccess->rolesAndPermissions($actor)['permissions'], true);
        $query = AnnotationProject::query();
        if (! $globalScope) {
            $query->where('organization_id', $actor->organization_id);
        }
        $target = $query->findOrFail($project);
        $export = $exports->create(
            $target, $actor, $request->validated('format'),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => [
                'export_id' => $export->annotation_export_id,
                'file_name' => $export->file_name,
                'storage_key' => $export->storage_key,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
