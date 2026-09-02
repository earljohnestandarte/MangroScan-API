<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportUpdateRequest;
use App\Http\Resources\ReportDraftResource;
use App\Models\User;
use App\Services\Report\ReportUpdateService;
use Illuminate\Http\JsonResponse;

class ReportUpdateController extends Controller
{
    // [RPT-04] Update content or archive a tenant-scoped draft with audit evidence.
    public function __invoke(ReportUpdateRequest $request, string $report, ReportUpdateService $reports): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $reports->update(
            $actor,
            $report,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ReportDraftResource($updated))->resolve($request),
        ]);
    }
}
