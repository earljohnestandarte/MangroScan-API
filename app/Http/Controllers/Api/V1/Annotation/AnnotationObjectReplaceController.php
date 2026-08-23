<?php

namespace App\Http\Controllers\Api\V1\Annotation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Annotation\AnnotationObjectReplaceRequest;
use App\Models\AnnotationItem;
use App\Models\User;
use App\Services\Annotation\AnnotationObjectReplacementService;
use App\Services\Auth\EffectiveAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AnnotationObjectReplaceController extends Controller
{
    // [ANN-03] Replace an annotation item's object set transactionally.
    public function __invoke(
        AnnotationObjectReplaceRequest $request,
        string $item,
        AnnotationObjectReplacementService $objects,
        EffectiveAccessService $effectiveAccess,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $globalScope = in_array('organizations.manage', $effectiveAccess->rolesAndPermissions($actor)['permissions'], true);
        $query = AnnotationItem::query();
        if (! $globalScope) {
            $query->whereHas('project', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });
        }
        $target = $query->findOrFail($item);
        $count = $objects->replace(
            $target, $actor, $request->validated('objects'),
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => ['count' => $count],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
