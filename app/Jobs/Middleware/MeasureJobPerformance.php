<?php

namespace App\Jobs\Middleware;

use App\Services\PerformanceMonitoring\PerformanceMonitor;
use Throwable;

/**
 * Around-advice style aspect on queue workers: times job handle() without changing job code.
 */
class MeasureJobPerformance
{
    public function __construct(
        private PerformanceMonitor $performanceMonitor,
    ) {}

    public function handle(object $job, callable $next): void
    {
        if (! $this->performanceMonitor->isEnabled()) {
            $next($job);

            return;
        }

        $started = microtime(true);
        $exception = null;

        try {
            $next($job);
        } catch (Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $started) * 1000;
            $this->performanceMonitor->recordJob($job::class, $durationMs, $exception);
        }
    }
}
