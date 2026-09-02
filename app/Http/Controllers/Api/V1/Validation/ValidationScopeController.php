<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Resources\ValidationSessionResource;
use App\Models\User;
use App\Services\Validation\ValidationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidationScopeController extends Controller
{
    // [VAL-01] Return tenant-safe validation form options and existing sessions.
    public function __invoke(Request $request, ValidationScopeService $scopes): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $options = $scopes->options($actor);

        return response()->json([
            'data' => [
                'missions' => $options['missions']->all(),
                'species' => $options['species']->all(),
                'assignees' => $options['assignees']->all(),
                'sessions' => ValidationSessionResource::collection($options['sessions'])->resolve($request),
            ],
        ]);
    }
}
