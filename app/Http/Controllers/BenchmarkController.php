<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Services\Benchmarking\BenchmarkComparisonBuilder;
use App\Services\Benchmarking\BenchmarkMetrics;
use App\Services\Benchmarking\OptimizedSalesReportService;
use App\Services\Benchmarking\SlowSalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/** Task 10: benchmark before/after sales report with span tracing. */
class BenchmarkController extends Controller
{
    public function salesReportSlow(
        Request $request,
        SlowSalesReportService $slowSalesReportService,
        BenchmarkComparisonBuilder $benchmarkComparisonBuilder,
    ): JsonResponse {
        $productId = max((int) $request->query('product_id', 1), 1);
        $payload = $slowSalesReportService->buildReport($productId);

        if ($payload['found'] ?? false) {
            $benchmarkComparisonBuilder->persistRun($payload, 'slow', $productId);
        }

        return $this->traceResponse($payload, $payload['found'] ?? false ? 200 : 404);
    }

    public function salesReportOptimized(
        Request $request,
        OptimizedSalesReportService $optimizedSalesReportService,
        BenchmarkComparisonBuilder $benchmarkComparisonBuilder,
    ): JsonResponse {
        $productId = max((int) $request->query('product_id', 1), 1);
        $payload = $optimizedSalesReportService->buildReport($productId);

        if ($payload['found'] ?? false) {
            $benchmarkComparisonBuilder->persistRun($payload, 'optimized', $productId);
        }

        return $this->traceResponse($payload, $payload['found'] ?? false ? 200 : 404);
    }

    public function comparison(BenchmarkMetrics $benchmarkMetrics): JsonResponse
    {
        if ($benchmarkMetrics->lastComparison !== null) {
            return response()->json([
                'message' => 'Benchmark before/after comparison (in memory)',
                'comparison' => $benchmarkMetrics->lastComparison,
            ]);
        }

        $jsonPath = (string) config('benchmarking.report_json_path');
        if (File::exists($jsonPath)) {
            $comparison = json_decode(File::get($jsonPath), true);

            return response()->json([
                'message' => 'Benchmark before/after comparison (from file)',
                'comparison' => $comparison,
            ]);
        }

        return response()->json([
            'message' => 'No comparison yet. Run GET /api/benchmark/sales-report/slow then optimized, or php artisan benchmark:compare',
            'comparison' => null,
        ]);
    }

    public function traces(): JsonResponse
    {
        $runs = BenchmarkRun::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (BenchmarkRun $run) => [
                'trace_id' => $run->trace_id,
                'mode' => $run->mode,
                'product_id' => $run->product_id,
                'total_duration_ms' => $run->total_duration_ms,
                'db_queries' => $run->db_queries,
                'bottleneck_span' => $run->bottleneck_span,
                'trace_spans' => $run->spans,
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'message' => 'Recent benchmark trace runs',
            'traces' => $runs,
        ]);
    }

    public function reset(
        BenchmarkMetrics $benchmarkMetrics,
    ): JsonResponse {
        BenchmarkRun::query()->delete();
        $benchmarkMetrics->reset();

        return response()->json([
            'message' => 'Benchmark runs and comparison metrics reset',
            'metrics' => $benchmarkMetrics->snapshot(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function traceResponse(array $payload, int $status): JsonResponse
    {
        $response = response()->json(array_merge([
            'message' => match ($payload['benchmark_mode'] ?? null) {
                'slow' => 'Slow sales report benchmark (sequential DB lookups — before optimization)',
                'optimized' => 'Optimized sales report benchmark (eager load — after optimization)',
                default => 'Benchmark sales report',
            },
        ], $payload), $status);

        if (! empty($payload['trace_id'])) {
            $response->headers->set('X-Trace-Id', (string) $payload['trace_id']);
        }

        if (! empty($payload['bottleneck_span'])) {
            $response->headers->set('X-Bottleneck-Span', (string) $payload['bottleneck_span']);
        }

        return $response;
    }
}
