<?php

namespace App\Services\Benchmarking;

use App\Models\BenchmarkRun;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\File;

/** Builds lecture-style benchmark demo view for /demo. */
class BenchmarkStatusBuilder
{
    public function build(BenchmarkMetrics $metrics, ?int $productId = null): array
    {
        $productId = $productId ?? 1;
        $snapshot = $metrics->snapshot();
        $comparison = $snapshot['last_comparison'];
        $minOrders = (int) config('benchmarking.demo_min_orders', 5);

        $orderCount = Order::query()
            ->where('product_id', $productId)
            ->where('status', 'success')
            ->count();

        $product = Product::query()->find($productId);
        $readyForDemo = $orderCount >= $minOrders && $product !== null;
        $jsonPath = (string) config('benchmarking.report_json_path');

        $dbTraces = BenchmarkRun::query()
            ->where('product_id', $productId)
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
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $lastSlow = $this->lastRunByMode($metrics->recentRuns(), 'slow');
        $lastOptimized = $this->lastRunByMode($metrics->recentRuns(), 'optimized');

        return [
            'comparison' => $comparison,
            'recent_runs' => $snapshot['recent_runs'],
            'db_traces' => $dbTraces,
            'order_count' => $orderCount,
            'ready_for_demo' => $readyForDemo,
            'demo_iterations' => (int) config('benchmarking.demo_iterations', 5),
            'demo_iterations_max' => (int) config('benchmarking.demo_iterations_max', 10),
            'demo_request_delay_ms' => (int) config('benchmarking.demo_request_delay_ms', 300),
            'sample_order_limit' => (int) config('benchmarking.sample_order_limit', 20),
            'demo_min_orders' => $minOrders,
            'example_product_id' => $productId,
            'product_snapshot' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock,
            ] : null,
            'last_slow_run' => $lastSlow,
            'last_optimized_run' => $lastOptimized,
            'has_comparison' => $comparison !== null,
            'report_file_exists' => File::exists($jsonPath),
            'report_json_path' => $jsonPath,
            'seed_hint_en' => $readyForDemo
                ? null
                : 'Few success orders for this product. Run full scenario (auto-seeds) or: php artisan db:seed --class=BenchmarkOrdersSeeder',
            'seed_hint_ar' => $readyForDemo
                ? null
                : 'عدد طلبات النجاح قليل. شغّل السيناريو الكامل (يزرع تلقائياً) أو: php artisan db:seed --class=BenchmarkOrdersSeeder',
            'comparison_message_en' => $comparison !== null ? ($comparison['explanation'] ?? null) : null,
            'comparison_message_ar' => $comparison !== null ? $this->messageAr($comparison) : null,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @param list<array<string, mixed>> $runs
     * @return array<string, mixed>|null
     */
    private function lastRunByMode(array $runs, string $mode): ?array
    {
        for ($i = count($runs) - 1; $i >= 0; $i--) {
            if (($runs[$i]['mode'] ?? '') === $mode) {
                return $runs[$i];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $comparison */
    private function messageAr(array $comparison): string
    {
        $before = $comparison['before'] ?? [];
        $after = $comparison['after'] ?? [];
        $improvement = $comparison['improvement'] ?? [];
        $span = $before['bottleneck_span'] ?? 'sequential_order_product_lookups';

        return sprintf(
            'الخطوات 1–2: تقرير المبيعات البطيء كشف عن عنق زجاجة "%s" (استعلامات متسلسلة لكل طلب). '
            .'الخطوة 3: استبدال الحلقة بـ eager loading (`Order::with("product")`). '
            .'الخطوات 4–5: متوسط زمن الاستجابة من %.2f ms إلى %.2f ms (%.1f%% أسرع) والاستعلامات من %d إلى %d.',
            $span,
            (float) ($before['avg_response_time_ms'] ?? 0),
            (float) ($after['avg_response_time_ms'] ?? 0),
            (float) ($improvement['response_time_percent_faster'] ?? 0),
            (int) ($before['avg_db_queries'] ?? 0),
            (int) ($after['avg_db_queries'] ?? 0),
        );
    }
}
