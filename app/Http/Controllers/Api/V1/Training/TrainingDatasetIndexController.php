<?php

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingDatasetIndexRequest;
use App\Http\Resources\TrainingDatasetResource;
use App\Models\TrainingDataset;
use Illuminate\Http\JsonResponse;

class TrainingDatasetIndexController extends Controller
{
    // [DATASET-01] List the global model-training dataset registry.
    public function __invoke(TrainingDatasetIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = TrainingDataset::query();
        if (! empty($validated['type'])) {
            $query->whereRaw('LOWER(dataset_type) = ?', [$validated['type']]);
        }
        if (! empty($validated['source'])) {
            $query->whereRaw('LOWER(source) = ?', [$validated['source']]);
        }

        $datasets = $query->orderBy('dataset_name')->orderByDesc('created_at')
            ->orderBy('training_dataset_id')->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => TrainingDatasetResource::collection(collect($datasets->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $datasets->currentPage(),
                'per_page' => $datasets->perPage(),
                'total' => $datasets->total(),
                'last_page' => $datasets->lastPage(),
            ],
        ]);
    }
}
