<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportStoreRequest;
use App\Http\Resources\ReportDraftResource;
use App\Models\User;
use App\Services\Report\ReportCreationService;
use Illuminate\Http\JsonResponse;

class ReportStoreController extends Controller
{
    // [RPT-02] Create an audited tenant-scoped report draft from the approved content contract.
    public function __invoke(ReportStoreRequest $request, ReportCreationService $reports): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $report = $reports->create(
            $actor,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->json([
            'data' => (new ReportDraftResource($report))->resolve($request),
        ], 201);
    }
}
