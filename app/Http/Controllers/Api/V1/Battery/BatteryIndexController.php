<?php

namespace App\Http\Controllers\Api\V1\Battery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Battery\BatteryIndexRequest;
use App\Http\Resources\BatteryResource;
use App\Models\Battery;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BatteryIndexController extends Controller
{
    // [BAT-01] List battery packs.
    public function __invoke(BatteryIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = Battery::query()
            ->where('organization_id', $actor->organization_id);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['type'])) {
            $query->where('battery_type', $validated['type']);
        }

        $batteries = $query
            ->orderBy('battery_code')
            ->orderBy('battery_id')
            ->paginate($perPage);

        return response()->json([
            'data' => BatteryResource::collection(collect($batteries->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $batteries->currentPage(),
                'per_page' => $batteries->perPage(),
                'total' => $batteries->total(),
                'last_page' => $batteries->lastPage(),
            ],
        ]);
    }
}
