<?php

namespace App\Services\Export;

use App\Models\ExportedFile;
use App\Models\User;

class ScopedExportedFileService
{
    public function find(User $actor, string $id): ExportedFile
    {
        return ExportedFile::query()
            ->join('reports', 'reports.report_id', '=', 'exported_files.report_id')
            ->join('survey_missions', 'survey_missions.mission_id', '=', 'exported_files.mission_id')
            ->join('survey_sites', 'survey_sites.site_id', '=', 'survey_missions.site_id')
            ->whereColumn('exported_files.mission_id', 'reports.mission_id')
            ->whereColumn('reports.site_id', 'survey_missions.site_id')
            ->where('survey_sites.organization_id', $actor->organization_id)
            ->whereNull('survey_missions.deleted_at')
            ->select('exported_files.*')
            ->findOrFail($id);
    }
}
