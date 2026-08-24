<?php

namespace App\Services\Export;

use App\Contracts\Export\PrivateDownloadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FilesystemPrivateDownloadUrlIssuer implements PrivateDownloadUrlIssuer
{
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): string
    {
        try {
            $filesystem = Storage::disk($disk);
            if (! $filesystem->exists($storageKey)) {
                throw new DownstreamServiceException('The export artifact is unavailable.', 503, 'SERVICE_UNAVAILABLE');
            }
            $url = $filesystem->temporaryUrl($storageKey, $expiresAt, [
                'ResponseContentDisposition' => 'attachment',
            ]);
        } catch (DownstreamServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownstreamServiceException('Private export download is unavailable.', 503, 'SERVICE_UNAVAILABLE');
        }
        if (! is_string($url) || $url === '') {
            throw new DownstreamServiceException('Private export download returned an invalid target.', 503, 'SERVICE_UNAVAILABLE');
        }

        return $url;
    }
}
