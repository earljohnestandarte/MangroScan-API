<?php

namespace App\Services\Media;

use App\Contracts\Media\PrivateObjectInspector;
use App\Exceptions\DownstreamServiceException;
use App\Exceptions\WorkflowConflictException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FilesystemPrivateObjectInspector implements PrivateObjectInspector
{
    /** @return array{size: int, checksum_sha256: string} */
    public function inspect(string $disk, string $storageKey): array
    {
        try {
            $filesystem = Storage::disk($disk);
            if (! $filesystem->exists($storageKey)) {
                throw new WorkflowConflictException(
                    'The uploaded object is not available for finalization.',
                    ['object_present' => false],
                );
            }
            $size = $filesystem->size($storageKey);
            $stream = $filesystem->readStream($storageKey);
            if (! is_resource($stream)) {
                throw new DownstreamServiceException(
                    'Private object verification is unavailable.', 503, 'SERVICE_UNAVAILABLE',
                );
            }

            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $checksum = hash_final($hash);
            } finally {
                fclose($stream);
            }
        } catch (WorkflowConflictException|DownstreamServiceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownstreamServiceException(
                'Private object verification is unavailable.', 503, 'SERVICE_UNAVAILABLE',
            );
        }

        return ['size' => (int) $size, 'checksum_sha256' => $checksum];
    }
}
