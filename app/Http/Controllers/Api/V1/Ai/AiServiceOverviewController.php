<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiServiceResource;
use App\Models\AiModel;
use App\Models\AiModelVersion;
use App\Models\AiService;
use App\Models\ProcessingJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiServiceOverviewController extends Controller
{
    private const SERVICE_COLUMNS = [
        'ai_service_id',
        'service_name',
        'base_url',
        'environment',
        'enabled',
        'health_status',
        'service_version',
        'capabilities',
        'last_health_checked_at',
        'last_synchronized_at',
        'created_by',
        'created_at',
        'updated_at',
    ];

    // [AISVC-01] Return the administrator's database-backed AI overview.
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $services = AiService::query()
            ->select(self::SERVICE_COLUMNS)
            ->orderByDesc('enabled')
            ->orderBy('service_name')
            ->orderBy('ai_service_id')
            ->get();

        $modelSummary = [
            'total' => AiModel::query()->count(),
            'deployed' => AiModel::query()
                ->whereHas('versions', fn (Builder $query) => $query->where('is_deployed', true))
                ->count(),
            'versions' => AiModelVersion::query()
                ->whereHas('model')
                ->count(),
        ];

        $jobs = ProcessingJob::query()
            ->whereHas('mission.site', function (Builder $query) use ($actor): void {
                $query->where('organization_id', $actor->organization_id);
            });
        $jobCounts = (clone $jobs)
            ->selectRaw('job_status, COUNT(*) AS aggregate')
            ->groupBy('job_status')
            ->pluck('aggregate', 'job_status');

        return response()->json([
            'data' => [
                'services' => AiServiceResource::collection($services)->resolve($request),
                'models' => $modelSummary,
                'jobs' => [
                    'total' => (clone $jobs)->count(),
                    'queued' => (int) ($jobCounts['queued'] ?? 0),
                    'running' => (int) ($jobCounts['running'] ?? 0),
                    'completed' => (int) ($jobCounts['completed'] ?? 0),
                    'failed' => (int) ($jobCounts['failed'] ?? 0),
                ],
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }
}
