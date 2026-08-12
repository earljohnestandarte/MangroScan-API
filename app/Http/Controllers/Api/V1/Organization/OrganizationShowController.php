<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationShowController extends Controller
{
    // [ORG-03] Return one non-deleted organization to an authorized system administrator.
    public function __invoke(Request $request, string $organization): JsonResponse
    {
        $organization = Organization::query()->findOrFail($organization);

        return response()->json([
            'data' => (new OrganizationResource($organization))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
