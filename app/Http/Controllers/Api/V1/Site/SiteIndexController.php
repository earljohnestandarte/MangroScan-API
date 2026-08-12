<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SiteIndexRequest;
use App\Http\Resources\SurveySiteResource;
use App\Models\SurveySite;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SiteIndexController extends Controller
{
    // [SITE-01] List non-deleted records visible inside the caller's organization boundary.
    public function __invoke(SiteIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = SurveySite::query()
            ->where('organization_id', $actor->organization_id);

        if (DB::getDriverName() === 'pgsql') {
            $query
                ->select('survey_sites.*')
                ->selectRaw('ST_AsGeoJSON(center_point)::json AS center_point_geojson');
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('site_name', $search)
                    ->orWhereLike('site_code', $search)
                    ->orWhereLike('description', $search)
                    ->orWhereLike('city_municipality', $search)
                    ->orWhereLike('barangay', $search);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['province'])) {
            $query->whereLike('province', $validated['province']);
        }

        $sites = $query
            ->orderBy('site_name')
            ->orderBy('site_id')
            ->paginate($perPage);

        return response()->json([
            'data' => SurveySiteResource::collection(collect($sites->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $sites->currentPage(),
                'per_page' => $sites->perPage(),
                'total' => $sites->total(),
                'last_page' => $sites->lastPage(),
            ],
        ]);
    }
}
