<?php

namespace App\Contracts\Media;

use DateTimeInterface;

interface PrivateDownloadUrlIssuer
{
    /** @return array{url: string} */
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array;
}
