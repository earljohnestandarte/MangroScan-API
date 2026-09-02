<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportDraftResource;
use App\Models\User;
use App\Services\Report\ReportDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportShowController extends Controller
{
    // [RPT-03] Return one tenant-scoped draft and its live canonical source summary.
    public function __invoke(Request $request, string $report, ReportDetailService $reports): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $reports->get($actor, $report);

        return response()->json(['data' => [
            'report' => (new ReportDraftResource($data['report']))->resolve($request),
            'source_summary' => $data['source_summary'],
        ]]);
    }
}
