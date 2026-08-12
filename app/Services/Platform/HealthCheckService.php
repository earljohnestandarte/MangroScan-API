<?php

namespace App\Services\Platform;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class HealthCheckService
{
    /**
     * @return array{available: bool, status: string, db: string, storage: string, queue: string, time: string}
     */
    public function check(): array
    {
        $checks = [
            'db' => $this->probe(fn () => DB::connection()->select('select 1')),
            'storage' => $this->probe(function (): void {
                $disk = config('filesystems.default');
                $configuration = config("filesystems.disks.{$disk}");

                if (! is_array($configuration)) {
                    throw new RuntimeException('The default filesystem disk is not configured.');
                }

                $configuration['throw'] = true;
                Storage::build($configuration)->exists('.mangroscan-healthcheck');
            }),
            'queue' => $this->probe(fn () => Queue::connection()->size()),
        ];

        $available = ! in_array('unavailable', $checks, true);

        return [
            'available' => $available,
            'status' => $available ? 'ok' : 'unavailable',
            ...$checks,
            'time' => now('UTC')->toIso8601String(),
        ];
    }

    private function probe(Closure $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
