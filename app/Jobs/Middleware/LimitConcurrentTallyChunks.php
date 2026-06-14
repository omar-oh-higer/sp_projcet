<?php

namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\Cache;

/**
 * Task 2 + Task 4: caps concurrent chunk workers (Fixed Thread Pool from Session 2).
 */
class LimitConcurrentTallyChunks
{
    public function handle(object $job, callable $next): void
    {
        $maxConcurrent = max((int) config('daily_sales_tally.max_concurrent_chunks', 5), 1);
        $semaphoreKey = (string) config('daily_sales_tally.semaphore_key', 'daily-sales-tally-chunk-semaphore');

        $acquired = false;
        $attempts = 0;
        $maxAttempts = 30;

        while (! $acquired && $attempts < $maxAttempts) {
            $currentCount = (int) Cache::get("{$semaphoreKey}:count", 0);

            if ($currentCount < $maxConcurrent) {
                Cache::put("{$semaphoreKey}:count", $currentCount + 1, 3600);
                $acquired = true;
            } else {
                $attempts++;
                if ($attempts < $maxAttempts) {
                    sleep(1);
                }
            }
        }

        if (! $acquired) {
            if (method_exists($job, 'release')) {
                $job->release(5);
            }

            return;
        }

        try {
            $next($job);
        } finally {
            $currentCount = (int) Cache::get("{$semaphoreKey}:count", 0);
            if ($currentCount > 0) {
                Cache::put("{$semaphoreKey}:count", $currentCount - 1, 3600);
            }
        }
    }
}
