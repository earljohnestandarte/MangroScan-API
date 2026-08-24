<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccuracyMetricResource;
use App\Http\Resources\GeospatialLayerResource;
use App\Http\Resources\GroundTruthTreeRecordResource;
use App\Http\Resources\TreeObservationResource;
use App\Http\Resources\ValidationMatchResource;
use App\Http\Resources\ValidationSessionResource;
use App\Models\User;
use App\Services\Validation\ValidationWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidationSessionShowController extends Controller
{
    // [VAL-04] Return the tenant-safe validation workspace and map-ready evidence.
    public function __invoke(Request $request, string $session, ValidationWorkspaceService $workspace): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $workspace->get($actor, $session);
        $record = $data['session'];
        $validator = $record->validator;

        return response()->json([
            'data' => [
                'context' => [
                    'session' => (new ValidationSessionResource($record))->resolve($request),
                    'mission' => [
                        'mission_id' => $record->mission->mission_id,
                        'mission_code' => $record->mission->mission_code,
                        'mission_title' => $record->mission->mission_title,
                        'status' => $record->mission->mission_status,
                    ],
                    'site' => [
                        'site_id' => $record->site->site_id,
                        'site_code' => $record->site->site_code,
                        'site_name' => $record->site->site_name,
                    ],
                    'plot' => $record->plot ? [
                        'plot_id' => $record->plot->plot_id,
                        'plot_code' => $record->plot->plot_code,
                        'plot_name' => $record->plot->plot_name,
                    ] : null,
                    'validator' => [
                        'user_id' => $validator->user_id,
                        'display_name' => collect([$validator->first_name, $validator->middle_name, $validator->last_name])
                            ->filter(fn (?string $part): bool => filled($part))
                            ->implode(' '),
                        'position_title' => $validator->position_title,
                    ],
                ],
                'observations' => TreeObservationResource::collection($data['observations'])->resolve($request),
                'ground_truth_records' => GroundTruthTreeRecordResource::collection($data['ground_truth_records'])->resolve($request),
                'matches' => ValidationMatchResource::collection($data['matches'])->resolve($request),
                'metrics' => AccuracyMetricResource::collection($data['metrics'])->resolve($request),
                'layers' => GeospatialLayerResource::collection($data['layers'])->resolve($request),
            ],
        ]);
    }
}
