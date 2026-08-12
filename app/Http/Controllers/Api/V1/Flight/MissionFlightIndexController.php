<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\FlightIndexRequest;
use App\Http\Resources\FlightSessionResource;
use App\Models\FlightSession;
use App\Models\User;
use App\Services\Mission\ScopedMissionService;
use Illuminate\Http\JsonResponse;

class MissionFlightIndexController extends Controller
{
    // [FLT-01] List sorties for one tenant-visible mission.
    public function __invoke(
        FlightIndexRequest $request,
        string $mission,
        ScopedMissionService $scoped,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $missionModel = $scoped->find($actor, $mission);
        $validated = $request->validated();

        $query = FlightSession::query()
            ->withLocationGeoJson()
            ->where('mission_id', $missionModel->mission_id);

        if (! empty($validated['status'])) {
            $query->where('flight_status', $validated['status']);
        }

        if (! empty($validated['quality_status'])) {
            $query->where('quality_status', $validated['quality_status']);
        }

        $flights = $query
            ->orderBy('flight_code')
            ->orderBy('flight_session_id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => FlightSessionResource::collection(collect($flights->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $flights->currentPage(),
                'per_page' => $flights->perPage(),
                'total' => $flights->total(),
                'last_page' => $flights->lastPage(),
            ],
        ]);
    }
}
