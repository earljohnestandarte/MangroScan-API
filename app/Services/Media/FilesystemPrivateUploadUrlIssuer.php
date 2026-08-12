<?php

namespace App\Services\Media;

use App\Contracts\Media\PrivateUploadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FilesystemPrivateUploadUrlIssuer implements PrivateUploadUrlIssuer
{
    /** @return array{url: string, headers: array<string, string>} */
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array
    {
        try {
            $filesystem = Storage::disk($disk);
            if (! $filesystem->providesTemporaryUploadUrls()) {
                throw new DownstreamServiceException(
                    'Private upload transport is unavailable.', 503, 'SERVICE_UNAVAILABLE',
                );
            }
            $target = $filesystem->temporaryUploadUrl($storageKey, $expiresAt);
        } catch (DownstreamServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownstreamServiceException(
                'Private upload transport is unavailable.', 503, 'SERVICE_UNAVAILABLE',
            );
        }

        if (! is_array($target) || ! is_string($target['url'] ?? null) || $target['url'] === '') {
            throw new DownstreamServiceException(
                'Private upload transport returned an invalid target.', 503, 'SERVICE_UNAVAILABLE',
            );
        }

        $headers = is_array($target['headers'] ?? null) ? $target['headers'] : [];

        return ['url' => $target['url'], 'headers' => $headers];
    }
}
