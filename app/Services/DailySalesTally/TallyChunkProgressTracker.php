<?php

namespace App\Services\DailySalesTally;

use Illuminate\Support\Facades\Cache;

/** Tracks in-flight chunk jobs per batch (for /demo live worker view). */
class TallyChunkProgressTracker
{
    public static function runningKey(string $batchId, int $chunkIndex): string
    {
        return "daily_sales_tally:running:{$batchId}:{$chunkIndex}";
    }

    public static function markRunning(string $batchId, int $chunkIndex, int $workerPid, int $workerTerminal = 0): void
    {
        Cache::put(self::runningKey($batchId, $chunkIndex), [
            'worker_pid' => $workerPid,
            'worker_terminal' => $workerTerminal,
            'chunk_index' => $chunkIndex,
            'started_at' => now()->toIso8601String(),
        ], 600);
    }

    public static function clearRunning(string $batchId, int $chunkIndex): void
    {
        Cache::forget(self::runningKey($batchId, $chunkIndex));
    }

    /**
     * @return list<array{chunk_index: int, worker_pid: int, started_at: string}>
     */
    public static function runningForBatch(string $batchId, int $expectedChunks): array
    {
        $running = [];

        for ($i = 0; $i < $expectedChunks; $i++) {
            $payload = Cache::get(self::runningKey($batchId, $i));

            if (is_array($payload) && isset($payload['worker_pid'])) {
                $running[] = [
                    'chunk_index' => $i,
                    'worker_pid' => (int) $payload['worker_pid'],
                    'worker_terminal' => (int) ($payload['worker_terminal'] ?? 0),
                    'started_at' => (string) ($payload['started_at'] ?? ''),
                ];
            }
        }

        return $running;
    }
}
