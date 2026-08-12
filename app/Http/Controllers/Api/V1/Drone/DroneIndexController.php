<?php

namespace App\Http\Controllers\Api\V1\Drone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Drone\DroneIndexRequest;
use App\Http\Resources\DroneResource;
use App\Models\Drone;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class DroneIndexController extends Controller
{
    // [DRONE-01] List tenant-owned drone units.
    public function __invoke(DroneIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = Drone::query()
            ->where('organization_id', $actor->organization_id);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('drone_name', $search)
                    ->orWhereLike('model', $search)
                    ->orWhereLike('serial_number', $search);
            });
        }

        $drones = $query
            ->orderBy('drone_name')
            ->orderBy('drone_id')
            ->paginate($perPage);

        return response()->json([
            'data' => DroneResource::collection(collect($drones->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $drones->currentPage(),
                'per_page' => $drones->perPage(),
                'total' => $drones->total(),
                'last_page' => $drones->lastPage(),
            ],
        ]);
    }
}
