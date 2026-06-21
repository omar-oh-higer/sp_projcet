<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Models\Order;
use App\Services\Benchmarking\BenchmarkComparisonBuilder;
use App\Services\Benchmarking\BenchmarkMetrics;
use App\Services\Benchmarking\BenchmarkOrchestrator;
use App\Services\Benchmarking\BenchmarkStatusBuilder;
use App\Services\Benchmarking\OptimizedSalesReportService;
use App\Services\Benchmarking\SlowSalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/** Task 10: benchmark before/after sales report with span tracing. */
class BenchmarkController extends Controller
{
    public function stats(
        Request $request,
        BenchmarkMetrics $benchmarkMetrics,
        BenchmarkStatusBuilder $benchmarkStatusBuilder,
    ): JsonResponse {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $view = $benchmarkStatusBuilder->build($benchmarkMetrics, $productId);

        return response()->json([
            'message' => 'Benchmark demo stats',
            'metrics' => $benchmarkMetrics->snapshot(),
            ...$view,
        ]);
    }

    public function salesReportSlow(
        Request $request,
        SlowSalesReportService $slowSalesReportService,
        BenchmarkComparisonBuilder $benchmarkComparisonBuilder,
        BenchmarkMetrics $benchmarkMetrics,
    ): JsonResponse {
        $productId = max((int) $request->query('product_id', 1), 1);
        $payload = $slowSalesReportService->buildReport($productId);

        if ($payload['found'] ?? false) {
            $benchmarkComparisonBuilder->persistRun($payload, 'slow', $productId);
            $benchmarkMetrics->recordRun([
                'mode' => 'slow',
                'product_id' => $productId,
                'total_duration_ms' => $payload['total_duration_ms'],
                'db_queries' => $payload['db_queries'],
                'bottleneck_span' => $payload['bottleneck_span'] ?? null,
                'trace_id' => $payload['trace_id'] ?? null,
            ]);
        }

        return $this->traceResponse($payload, $payload['found'] ?? false ? 200 : 404);
    }

    public function salesReportOptimized(
        Request $request,
        OptimizedSalesReportService $optimizedSalesReportService,
        BenchmarkComparisonBuilder $benchmarkComparisonBuilder,
        BenchmarkMetrics $benchmarkMetrics,
    ): JsonResponse {
        $productId = max((int) $request->query('product_id', 1), 1);
        $payload = $optimizedSalesReportService->buildReport($productId);

        if ($payload['found'] ?? false) {
            $benchmarkComparisonBuilder->persistRun($payload, 'optimized', $productId);
            $benchmarkMetrics->recordRun([
                'mode' => 'optimized',
                'product_id' => $productId,
                'total_duration_ms' => $payload['total_duration_ms'],
                'db_queries' => $payload['db_queries'],
                'bottleneck_span' => $payload['bottleneck_span'] ?? null,
                'trace_id' => $payload['trace_id'] ?? null,
            ]);
        }

        return $this->traceResponse($payload, $payload['found'] ?? false ? 200 : 404);
    }

    public function demoRun(Request $request, BenchmarkOrchestrator $orchestrator): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $demoIterations = (int) config('benchmarking.demo_iterations', 5);
        $maxIterations = (int) config('benchmarking.demo_iterations_max', 10);
        $iterations = min(max($request->integer('iterations') ?: $demoIterations, 1), $maxIterations);
        $writeReport = $request->boolean('write_report', true);

        try {
            $orchestrator->assertProductExists($productId);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        $orchestrator->ensureDemoData($productId);

        try {
            $result = $orchestrator->runComparison(
                productId: $productId,
                iterations: $iterations,
                writeReport: $writeReport,
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'comparison' => null,
            ], 422);
        }

        return response()->json([
            'message' => 'Benchmark comparison completed',
            'iterations' => $iterations,
            'product_id' => $productId,
            'comparison' => $result['comparison'],
            'slow_samples' => count($result['slow_samples']),
            'optimized_samples' => count($result['optimized_samples']),
        ]);
    }

    public function demoReset(Request $request, BenchmarkOrchestrator $orchestrator, BenchmarkMetrics $benchmarkMetrics): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $ensureSeed = $request->boolean('ensure_seed', true);

        BenchmarkRun::query()->delete();
        $benchmarkMetrics->reset();

        $jsonPath = (string) config('benchmarking.report_json_path');
        $markdownPath = (string) config('benchmarking.report_markdown_path');

        if ($request->boolean('clear_report', false)) {
            if (File::exists($jsonPath)) {
                File::delete($jsonPath);
            }
            if (File::exists($markdownPath)) {
                File::delete($markdownPath);
            }
        }

        $orderCount = Order::query()
            ->where('product_id', $productId)
            ->where('status', 'success')
            ->count();

        if ($ensureSeed && $orderCount < (int) config('benchmarking.demo_min_orders', 5)) {
            $orderCount = $orchestrator->ensureDemoData($productId);
        }

        return response()->json([
            'message' => 'Benchmark runs and metrics reset'.($ensureSeed ? '; demo orders ensured.' : '.'),
            'product_id' => $productId,
            'order_count' => $orderCount,
            'metrics_reset' => true,
            'report_cleared' => $request->boolean('clear_report', false),
        ]);
    }

    public function comparison(BenchmarkMetrics $benchmarkMetrics): JsonResponse
    {
        $cached = $benchmarkMetrics->lastComparison();

        if ($cached !== null) {
            return response()->json([
                'message' => 'Benchmark before/after comparison (from cache)',
                'comparison' => $cached,
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
            'message' => 'No comparison yet. Run POST /api/benchmark/demo-run or php artisan benchmark:compare',
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
