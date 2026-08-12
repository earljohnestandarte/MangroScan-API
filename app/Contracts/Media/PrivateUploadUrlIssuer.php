<?php

namespace App\Contracts\Media;

use DateTimeInterface;

interface PrivateUploadUrlIssuer
{
    /** @return array{url: string, headers: array<string, string>} */
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): array;
}
