<?php

namespace App\Services\DailySalesTally;

use Illuminate\Support\Facades\Cache;

/** Maps queue:work OS process → terminal #1..N for a batch (lecture demo). */
class TallyWorkerRegistry
{
    public static function mapKey(string $batchId): string
    {
        return "daily_sales_tally:terminals:{$batchId}";
    }

    /**
     * First time a PID appears in this batch it gets the next terminal number (1, 2, 3…).
     */
    public static function terminalForProcess(string $batchId, int $processId): int
    {
        if ($processId <= 0) {
            return 0;
        }

        $key = self::mapKey($batchId);
        /** @var array<int, int> $map pid => terminal */
        $map = Cache::get($key, []);

        if (isset($map[$processId])) {
            return (int) $map[$processId];
        }

        $map[$processId] = count($map) + 1;
        Cache::put($key, $map, 3600);

        return (int) $map[$processId];
    }

    /** @return array<int, int> pid => terminal */
    public static function terminalMap(string $batchId): array
    {
        /** @var array<int, int> $map */
        $map = Cache::get(self::mapKey($batchId), []);

        return $map;
    }

    public static function forgetBatch(string $batchId): void
    {
        Cache::forget(self::mapKey($batchId));
    }
}
