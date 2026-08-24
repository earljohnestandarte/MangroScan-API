<?php

namespace App\Contracts\Export;

use DateTimeInterface;

interface PrivateDownloadUrlIssuer
{
    public function issue(string $disk, string $storageKey, DateTimeInterface $expiresAt): string;
}
