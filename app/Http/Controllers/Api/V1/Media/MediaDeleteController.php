<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Media\MediaDeleteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaDeleteController extends Controller
{
    public function __invoke(Request $request, string $media, MediaDeleteService $deletion): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $deletion->delete(
            $actor,
            $media,
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}
