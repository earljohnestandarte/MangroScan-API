<?php

namespace App\Http\Controllers\Api\V1\Export;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Export\ExportDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $exportedFile, ExportDownloadService $downloads): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $downloads->issue(
            $actor, $exportedFile, $request->ip(), $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => $target]);
    }
}
