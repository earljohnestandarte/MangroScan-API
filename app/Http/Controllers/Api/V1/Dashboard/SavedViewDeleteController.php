<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\SavedViewService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SavedViewDeleteController extends Controller
{
    public function __invoke(Request $request, string $view, SavedViewService $service): Response
    {
        $service->delete($request->user(), $view, $request->ip(), $request->userAgent(), $request->attributes->get('request_id'));
        return response()->noContent();
    }
}
