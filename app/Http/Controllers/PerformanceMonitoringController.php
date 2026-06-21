<?php

namespace App\Http\Controllers;

use App\Services\PerformanceMonitoring\PerformanceMonitor;
use App\Services\PerformanceMonitoring\PerformanceStatusBuilder;
use Illuminate\Http\JsonResponse;

/** AOP demo: read aggregated performance measurements recorded by middleware aspects. */
class PerformanceMonitoringController extends Controller
{
    public function stats(
        PerformanceMonitor $performanceMonitor,
        PerformanceStatusBuilder $performanceStatusBuilder,
    ): JsonResponse {
        return response()->json([
            'message' => 'Performance measurements collected by AOP around-advice middleware.',
            ...$performanceStatusBuilder->build($performanceMonitor),
        ]);
    }

    public function reset(PerformanceMonitor $performanceMonitor): JsonResponse
    {
        $performanceMonitor->reset();

        return response()->json([
            'message' => 'Performance measurement records cleared.',
            'metrics_reset' => true,
        ]);
    }

    public function demoReset(PerformanceMonitor $performanceMonitor): JsonResponse
    {
        return $this->reset($performanceMonitor);
    }
}
