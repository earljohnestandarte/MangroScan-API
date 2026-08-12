<?php

namespace App\Contracts\Media;

interface PrivateObjectInspector
{
    /** @return array{size: int, checksum_sha256: string} */
    public function inspect(string $disk, string $storageKey): array;
}
