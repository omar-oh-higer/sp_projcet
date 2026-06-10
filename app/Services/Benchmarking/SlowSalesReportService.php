<?php

namespace App\Services\Benchmarking;

use App\Models\Order;
use App\Models\Product;

/** Task 10 before: sequential order+product DB lookups (N+1 bottleneck). */
class SlowSalesReportService
{
    /**
     * @return array<string, mixed>
     */
    public function buildReport(int $productId): array
    {
        $tracer = new RequestSpanTracer();
        $dbQueries = 0;
        $limit = (int) config('benchmarking.sample_order_limit', 20);
        $delayMs = max((int) config('benchmarking.sequential_query_delay_ms', 5), 0);

        $started = microtime(true);

        $product = $tracer->trace('load_product', function () use ($productId, &$dbQueries) {
            $dbQueries++;

            return Product::query()->find($productId);
        });

        if (! $product) {
            return $this->notFoundPayload($tracer, microtime(true) - $started, $dbQueries);
        }

        $lines = $tracer->trace('sequential_order_product_lookups', function () use ($productId, $limit, $delayMs, &$dbQueries) {
            $orderIds = Order::query()
                ->where('product_id', $productId)
                ->where('status', 'success')
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('id');

            $dbQueries++;

            $rows = [];

            foreach ($orderIds as $orderId) {
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $order = Order::query()->find($orderId);
                $dbQueries++;

                if (! $order) {
                    continue;
                }

                $productName = $order->product->name;
                $dbQueries++;

                $rows[] = [
                    'order_id' => $order->id,
                    'quantity' => $order->quantity,
                    'product_name' => $productName,
                ];
            }

            return $rows;
        });

        $totalDurationMs = round((microtime(true) - $started) * 1000, 3);
        $bottleneck = $tracer->bottleneckSpan();

        return [
            'found' => true,
            'benchmark_mode' => 'slow',
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
        ];
    }

    /** @return array<string, mixed> */
    private function notFoundPayload(RequestSpanTracer $tracer, float $elapsedSeconds, int $dbQueries): array
    {
        $totalDurationMs = round($elapsedSeconds * 1000, 3);

        return [
            'found' => false,
            'benchmark_mode' => 'slow',
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
