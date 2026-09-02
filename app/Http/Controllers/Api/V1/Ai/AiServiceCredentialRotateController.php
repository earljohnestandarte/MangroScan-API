<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiServiceCredentialRotateRequest;
use App\Models\AiService;
use App\Models\User;
use App\Services\Ai\AiServiceCredentialRotationService;
use Illuminate\Http\Response;

class AiServiceCredentialRotateController extends Controller
{
    // [AISVC-05] Rotate a service credential without returning or auditing the secret.
    public function __invoke(
        AiServiceCredentialRotateRequest $request,
        string $service,
        AiServiceCredentialRotationService $rotation,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $target = AiService::query()->findOrFail($service);

        $rotation->rotate(
            $target,
            $actor,
            $request->validated('api_key'),
            $request->ip(),
            $request->userAgent(),
            $request->attributes->get('request_id'),
        );

        return response()->noContent();
    }
}
