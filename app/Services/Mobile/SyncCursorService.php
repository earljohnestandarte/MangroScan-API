<?php

namespace App\Services\Mobile;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class SyncCursorService
{
    public function encode(CarbonImmutable $boundary): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'boundary' => $boundary->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(?string $cursor): ?CarbonImmutable
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ($payload['version'] ?? null) !== 1 || ! is_string($payload['boundary'] ?? null)) {
                throw new DecryptException('Invalid cursor payload.');
            }

            $boundary = CarbonImmutable::parse($payload['boundary'])->utc();

            if ($boundary->isFuture()) {
                throw new DecryptException('Future cursor boundary.');
            }

            return $boundary;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid or no longer supported.'],
            ]);
        }
    }
}
