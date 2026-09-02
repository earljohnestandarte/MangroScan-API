<?php

namespace App\Http\Controllers\Api\V1\Setting;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = SystemSetting::query();
        if (is_string($request->input('group'))) $query->where('setting_group', trim($request->input('group')));
        return response()->json(['data' => SystemSettingResource::collection($query->orderBy('setting_group')->orderBy('setting_key')->get())->resolve($request), 'meta' => ['request_id' => $request->attributes->get('request_id')] ]);
    }
}
