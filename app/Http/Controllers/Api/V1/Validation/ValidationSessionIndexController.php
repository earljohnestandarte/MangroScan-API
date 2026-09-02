<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Validation\ValidationSessionIndexRequest;
use App\Http\Resources\ValidationSessionResource;
use App\Models\User;
use App\Services\Validation\ValidationSessionIndexService;
use Illuminate\Http\JsonResponse;

class ValidationSessionIndexController extends Controller
{
    // [VAL-02] List tenant-safe field validation sessions using the approved fixed page size.
    public function __invoke(
        ValidationSessionIndexRequest $request,
        ValidationSessionIndexService $sessions,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $page = $sessions->paginate($actor, $request->validated());

        return response()->json([
            'data' => ValidationSessionResource::collection(collect($page->items()))->resolve($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
