<?php

namespace App\Http\Middleware;

use App\Services\PerformanceMonitoring\PerformanceMonitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Around-advice style aspect: measures total HTTP request time without touching controllers.
 */
class MeasureRequestPerformance
{
    public function __construct(
        private PerformanceMonitor $performanceMonitor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->performanceMonitor->isEnabled()) {
            return $next($request);
        }

        $started = microtime(true);
        $response = $next($request);
        $durationMs = (microtime(true) - $started) * 1000;

        $this->performanceMonitor->recordHttp($request, $response, $durationMs);

        if (config('performance_monitoring.expose_response_header', true)) {
            $response->headers->set('X-Response-Time-Ms', (string) round($durationMs, 3));
        }

        return $response;
    }
}
