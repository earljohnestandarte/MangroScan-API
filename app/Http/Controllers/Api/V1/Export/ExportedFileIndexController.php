<?php

namespace App\Http\Controllers\Api\V1\Export;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportedFileIndexRequest;
use App\Http\Resources\ExportedFileResource;
use App\Models\ExportedFile;
use App\Models\SurveyMission;
use App\Models\User;
use App\Services\Report\ScopedReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ExportedFileIndexController extends Controller
{
    public function __invoke(ExportedFileIndexRequest $request, ScopedReportService $reports): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validated();
        $report = ! empty($filters['report_id']) ? $reports->find($actor, $filters['report_id']) : null;
        $mission = null;
        if (! empty($filters['mission_id'])) {
            $mission = SurveyMission::query()->whereNull('deleted_at')
                ->whereHas('site', fn (Builder $query) => $query->where('organization_id', $actor->organization_id))
                ->findOrFail($filters['mission_id']);
        }
        if ($report !== null && $mission !== null && $report->mission_id !== $mission->mission_id) {
            throw new NotFoundHttpException;
        }

        $query = ExportedFile::query()
            ->join('reports', 'reports.report_id', '=', 'exported_files.report_id')
            ->join('survey_missions', 'survey_missions.mission_id', '=', 'exported_files.mission_id')
            ->join('survey_sites', 'survey_sites.site_id', '=', 'survey_missions.site_id')
            ->whereColumn('exported_files.mission_id', 'reports.mission_id')
            ->whereColumn('reports.site_id', 'survey_missions.site_id')
            ->where('survey_sites.organization_id', $actor->organization_id)
            ->whereNull('survey_missions.deleted_at')
            ->select([
                'exported_files.export_file_id', 'exported_files.report_id', 'exported_files.mission_id',
                'exported_files.export_type', 'exported_files.file_name', 'exported_files.file_size_bytes',
                'exported_files.exported_by', 'exported_files.exported_at',
            ]);
        if ($report !== null) {
            $query->where('exported_files.report_id', $report->report_id);
        }
        if ($mission !== null) {
            $query->where('exported_files.mission_id', $mission->mission_id);
        }
        if (! empty($filters['type'])) {
            $query->where('exported_files.export_type', $filters['type']);
        }
        $files = $query->orderByDesc('exported_files.exported_at')->orderByDesc('exported_files.export_file_id')->paginate(25);

        return response()->json([
            'data' => ExportedFileResource::collection(collect($files->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'), 'page' => $files->currentPage(),
                'per_page' => $files->perPage(), 'total' => $files->total(), 'last_page' => $files->lastPage(),
            ],
        ]);
    }
}
