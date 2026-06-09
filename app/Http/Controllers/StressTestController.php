<?php

namespace App\Http\Controllers;

use App\Services\StressTesting\StressTestMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

/** Task 9: read last concurrent stress test report (execution via Artisan only). */
class StressTestController extends Controller
{
    public function lastReport(StressTestMetrics $stressTestMetrics): JsonResponse
    {
        $jsonPath = (string) config('stress_testing.report_json_path');

        if ($stressTestMetrics->lastReport !== null) {
            return response()->json([
                'message' => 'Last stress test report (in memory)',
                'report' => $stressTestMetrics->lastReport,
            ]);
        }

        if (File::exists($jsonPath)) {
            $report = json_decode(File::get($jsonPath), true);

            return response()->json([
                'message' => 'Last stress test report (from file)',
                'report' => $report,
            ]);
        }

        return response()->json([
            'message' => 'No stress test report yet. Run: php artisan stress:concurrent --users=100 --scenario=safe',
            'report' => null,
        ]);
    }

    public function reset(StressTestMetrics $stressTestMetrics): JsonResponse
    {
        $stressTestMetrics->reset();

        return response()->json([
            'message' => 'Stress test metrics reset',
            'metrics' => $stressTestMetrics->snapshot(),
        ]);
    }
}
