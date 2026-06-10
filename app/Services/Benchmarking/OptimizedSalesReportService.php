<?php

namespace App\Services\Benchmarking;

use App\Models\Order;
use App\Models\Product;

/** Task 10 after: eager-loaded orders + product (optimized). */
class OptimizedSalesReportService
{
    /**
     * @return array<string, mixed>
     */
    public function buildReport(int $productId): array
    {
        $tracer = new RequestSpanTracer();
        $dbQueries = 0;
        $limit = (int) config('benchmarking.sample_order_limit', 20);

        $started = microtime(true);

        $product = $tracer->trace('load_product', function () use ($productId, &$dbQueries) {
            $dbQueries++;

            return Product::query()->find($productId);
        });

        if (! $product) {
            return $this->notFoundPayload($tracer, microtime(true) - $started, $dbQueries);
        }

        $lines = $tracer->trace('eager_load_orders_with_product', function () use ($productId, $limit, &$dbQueries) {
            $orders = Order::query()
                ->with('product')
                ->where('product_id', $productId)
                ->where('status', 'success')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $dbQueries += 2;

            return $orders->map(fn (Order $order) => [
                'order_id' => $order->id,
                'quantity' => $order->quantity,
                'product_name' => $order->product?->name,
            ])->all();
        });

        $totalDurationMs = round((microtime(true) - $started) * 1000, 3);
        $bottleneck = $tracer->bottleneckSpan();

        return [
            'found' => true,
            'benchmark_mode' => 'optimized',
            'trace_id' => $tracer->traceId,
            'product_id' => $productId,
            'total_duration_ms' => $totalDurationMs,
            'db_queries' => $dbQueries,
            'order_count' => count($lines),
            'orders' => $lines,
            'trace_spans' => $tracer->spans(),
            'bottleneck_span' => $bottleneck['name'] ?? null,
            'bottleneck_duration_ms' => $bottleneck['duration_ms'] ?? null,
            'bottleneck_analysis' => $tracer->bottleneckAnalysis($totalDurationMs),
            'optimization' => 'eager_load_with_product',
        ];
    }

    /** @return array<string, mixed> */
    private function notFoundPayload(RequestSpanTracer $tracer, float $elapsedSeconds, int $dbQueries): array
    {
        $totalDurationMs = round($elapsedSeconds * 1000, 3);

        return [
            'found' => false,
            'benchmark_mode' => 'optimized',
            'trace_id' => $tracer->traceId,
            'total_duration_ms' => $totalDurationMs,
            'db_queries' => $dbQueries,
            'trace_spans' => $tracer->spans(),
            'bottleneck_span' => null,
            'bottleneck_duration_ms' => null,
            'bottleneck_analysis' => null,
        ];
    }
}
