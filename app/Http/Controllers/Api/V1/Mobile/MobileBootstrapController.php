<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileBootstrapRequest;
use App\Http\Resources\FlightSessionResource;
use App\Http\Resources\SurveyMissionResource;
use App\Models\User;
use App\Services\Mobile\MobileBootstrapService;
use App\Services\Mobile\SyncCursorService;
use Illuminate\Http\JsonResponse;

class MobileBootstrapController extends Controller
{
    // [SYNC-02] Return a cursor-bounded tenant snapshot for offline clients.
    public function __invoke(
        MobileBootstrapRequest $request,
        MobileBootstrapService $bootstrap,
        SyncCursorService $cursors,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $after = $cursors->decode($request->validated('cursor'));
        $snapshot = $bootstrap->snapshot($actor, $after);

        return response()->json([
            'data' => [
                'missions' => SurveyMissionResource::collection($snapshot['missions'])->resolve($request),
                'flights' => FlightSessionResource::collection($snapshot['flights'])->resolve($request),
                'checklist_templates' => [],
                'settings' => [],
                'tombstones' => $snapshot['tombstones']->all(),
            ],
            'meta' => [
                'cursor' => $cursors->encode($snapshot['serverTime']),
                'server_time' => $snapshot['serverTime']->toIso8601String(),
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
