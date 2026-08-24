<?php

namespace App\Services\Export;

class StoredZipBuilder
{
    /** @param array<string, string> $files */
    public function build(array $files): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($files as $name => $contents) {
            $crc = (int) sprintf('%u', crc32($contents));
            $size = strlen($contents);
            $nameLength = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0)
                .$name.$contents;
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $size, $size,
                $nameLength, 0, 0, 0, 0, 0, $offset,
            ).$name;
            $body .= $local;
            $offset += strlen($local);
            $count++;
        }

        return $body.$central.pack(
            'VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), strlen($body), 0,
        );
    }
}
