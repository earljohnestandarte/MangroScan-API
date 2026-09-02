<?php

namespace App\Http\Controllers\Api\V1\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\SystemSettingUpdateRequest;
use App\Http\Resources\SystemSettingResource;
use App\Services\Setting\SystemSettingService;
use Illuminate\Http\JsonResponse;

class SystemSettingUpdateController extends Controller
{
    public function __invoke(SystemSettingUpdateRequest $request, string $key, SystemSettingService $service): JsonResponse
    {
        $setting = $service->update($request->user(), $key, $request->validated(), $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));
        return response()->json(['data' => (new SystemSettingResource($setting))->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')] ]);
    }
}
