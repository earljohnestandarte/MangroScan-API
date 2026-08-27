<?php

namespace App\Http\Controllers\Api\V1\Mission;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mission\MissionDeleteService;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MissionDeleteController extends Controller
{
    // [MSN-05] Archive a planned mission using soft delete.
    public function __invoke(
        Request $request,
        string $mission,
        ScopedMissionService $scoped,
        MissionDeleteService $deletion,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();

        $target = $scoped->find($actor, $mission);

        $deletion->delete(
            $target,
            $actor,
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}