<?php

namespace App\Http\Controllers\Api\V1\Battery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Battery\BatteryStoreRequest;
use App\Http\Resources\BatteryResource;
use App\Models\User;
use App\Services\Battery\BatteryCreationService;
use Illuminate\Http\JsonResponse;

class BatteryStoreController extends Controller
{
    public function __invoke(BatteryStoreRequest $request, BatteryCreationService $creation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $battery = $creation->create(
            $actor,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new BatteryResource($battery))->resolve($request),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }
}
