<?php

namespace App\Services\Benchmarking;

use App\Models\Order;
use App\Models\Product;
use Database\Seeders\BenchmarkOrdersSeeder;

/** Task 10: orchestrates in-process benchmark demo runs for the web UI. */
class BenchmarkOrchestrator
{
    public function __construct(
        private SlowSalesReportService $slowSalesReportService,
        private OptimizedSalesReportService $optimizedSalesReportService,
        private BenchmarkComparisonBuilder $benchmarkComparisonBuilder,
        private BenchmarkMetrics $benchmarkMetrics,
    ) {}

    public function ensureDemoData(int $productId): int
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            return 0;
        }

        $minOrders = (int) config('benchmarking.demo_min_orders', 5);

        $orderCount = Order::query()
            ->where('product_id', $productId)
            ->where('status', 'success')
            ->count();

        if ($orderCount < $minOrders) {
            (new BenchmarkOrdersSeeder)->run();
            $orderCount = Order::query()
                ->where('product_id', $productId)
                ->where('status', 'success')
                ->count();
        }

        return $orderCount;
    }

    /**
     * @return array{comparison: array<string, mixed>, slow_samples: list<array<string, mixed>>, optimized_samples: list<array<string, mixed>>}
     */
    public function runComparison(int $productId, int $iterations, bool $writeReport = true): array
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            throw new \InvalidArgumentException('Product not found for id '.$productId);
        }

        $slowSamples = [];
        $optimizedSamples = [];
        $bottleneckSpan = null;

        for ($i = 0; $i < $iterations; $i++) {
            $slowPayload = $this->slowSalesReportService->buildReport($productId);

            if ($slowPayload['found'] ?? false) {
                $this->benchmarkComparisonBuilder->persistRun($slowPayload, 'slow', $productId);
                $this->benchmarkMetrics->recordRun([
                    'mode' => 'slow',
                    'product_id' => $productId,
                    'total_duration_ms' => $slowPayload['total_duration_ms'],
                    'db_queries' => $slowPayload['db_queries'],
                    'bottleneck_span' => $slowPayload['bottleneck_span'] ?? null,
                    'trace_id' => $slowPayload['trace_id'] ?? null,
                ]);

                $slowSamples[] = [
                    'total_duration_ms' => (float) ($slowPayload['total_duration_ms'] ?? 0),
                    'db_queries' => (int) ($slowPayload['db_queries'] ?? 0),
                ];
                $bottleneckSpan = $slowPayload['bottleneck_span'] ?? $bottleneckSpan;
            }

            $optimizedPayload = $this->optimizedSalesReportService->buildReport($productId);

            if ($optimizedPayload['found'] ?? false) {
                $this->benchmarkComparisonBuilder->persistRun($optimizedPayload, 'optimized', $productId);
                $this->benchmarkMetrics->recordRun([
                    'mode' => 'optimized',
                    'product_id' => $productId,
                    'total_duration_ms' => $optimizedPayload['total_duration_ms'],
                    'db_queries' => $optimizedPayload['db_queries'],
                    'bottleneck_span' => $optimizedPayload['bottleneck_span'] ?? null,
                    'trace_id' => $optimizedPayload['trace_id'] ?? null,
                ]);

                $optimizedSamples[] = [
                    'total_duration_ms' => (float) ($optimizedPayload['total_duration_ms'] ?? 0),
                    'db_queries' => (int) ($optimizedPayload['db_queries'] ?? 0),
                ];
            }
        }

        if ($slowSamples === [] || $optimizedSamples === []) {
            throw new \RuntimeException('Benchmark could not collect samples — ensure demo orders exist for product #'.$productId);
        }

        $comparison = $this->benchmarkComparisonBuilder->build(
            productId: $productId,
            slowSamples: $slowSamples,
            optimizedSamples: $optimizedSamples,
            bottleneckSpan: $bottleneckSpan,
        );

        if ($writeReport) {
            $this->benchmarkComparisonBuilder->writeReportFiles($comparison, 'both');
        }

        return [
            'comparison' => $comparison,
            'slow_samples' => $slowSamples,
            'optimized_samples' => $optimizedSamples,
        ];
    }

    public function assertProductExists(int $productId): Product
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            throw new \InvalidArgumentException('Product not found for id '.$productId);
        }

        return $product;
    }
}
