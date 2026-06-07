<?php

namespace App\Http\Controllers;

use App\Services\PerformanceMonitoring\PerformanceMonitor;
use Illuminate\Http\JsonResponse;

/** AOP demo: read aggregated performance measurements recorded by middleware aspects. */
class PerformanceMonitoringController extends Controller
{
    public function stats(PerformanceMonitor $performanceMonitor): JsonResponse
    {
        return response()->json([
            'message' => 'Performance measurements collected by AOP around-advice middleware.',
            ...$performanceMonitor->stats(),
        ]);
    }

    public function reset(PerformanceMonitor $performanceMonitor): JsonResponse
    {
        $performanceMonitor->reset();

        return response()->json([
            'message' => 'Performance measurement records cleared.',
        ]);
    }
}
