<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportApprovalRequest;
use App\Http\Resources\ReportDraftResource;
use App\Models\User;
use App\Services\Report\ReportApprovalService;
use Illuminate\Http\JsonResponse;

class ReportApprovalController extends Controller
{
    // [RPT-06] Record an audited approval or return a rejected artifact to draft.
    public function __invoke(ReportApprovalRequest $request, string $report, ReportApprovalService $approval): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();
        $updated = $approval->decide(
            $actor, $report, $data['decision'], $data['notes'] ?? null,
            $request->ip(), $request->userAgent(), $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ReportDraftResource($updated))->resolve($request),
        ]);
    }
}
