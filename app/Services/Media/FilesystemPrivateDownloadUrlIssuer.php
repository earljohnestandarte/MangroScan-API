<?php

namespace App\Services\Media;

use App\Contracts\Media\PrivateDownloadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FilesystemPrivateDownloadUrlIssuer implements PrivateDownloadUrlIssuer
{
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array
    {
        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($storageKey)) {
                throw new DownstreamServiceException(
                    'The media object is unavailable.',
                    503,
                    'SERVICE_UNAVAILABLE',
                );
            }

            if (! $filesystem->providesTemporaryUrls()) {
                throw new DownstreamServiceException(
                    'Private download transport is unavailable.',
                    503,
                    'SERVICE_UNAVAILABLE',
                );
            }

            $url = $filesystem->temporaryUrl($storageKey, $expiresAt);
        } catch (DownstreamServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownstreamServiceException(
                'Private download transport is unavailable.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        if (! is_string($url) || $url === '') {
            throw new DownstreamServiceException(
                'Private download transport returned an invalid URL.',
                503,
                'SERVICE_UNAVAILABLE',
            );
        }

        return ['url' => $url];
    }
}
