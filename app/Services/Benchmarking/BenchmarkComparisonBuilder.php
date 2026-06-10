<?php

namespace App\Services\Benchmarking;

use App\Models\BenchmarkRun;
use Illuminate\Support\Facades\File;

/** Task 10: builds before/after benchmark comparison reports. */
class BenchmarkComparisonBuilder
{
    public function __construct(
        private BenchmarkMetrics $benchmarkMetrics,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $slowSamples
     * @param  list<array<string, mixed>>  $optimizedSamples
     * @return array<string, mixed>
     */
    public function build(
        int $productId,
        array $slowSamples,
        array $optimizedSamples,
        ?string $bottleneckSpan = null,
    ): array {
        $beforeAvgMs = $this->average($slowSamples, 'total_duration_ms');
        $afterAvgMs = $this->average($optimizedSamples, 'total_duration_ms');
        $beforeDbQueries = (int) round($this->average($slowSamples, 'db_queries'));
        $afterDbQueries = (int) round($this->average($optimizedSamples, 'db_queries'));

        $improvementMs = round($beforeAvgMs - $afterAvgMs, 3);
        $improvementPercent = $beforeAvgMs > 0
            ? round(($improvementMs / $beforeAvgMs) * 100, 1)
            : 0.0;

        $queryReductionPercent = $beforeDbQueries > 0
            ? round((($beforeDbQueries - $afterDbQueries) / $beforeDbQueries) * 100, 1)
            : 0.0;

        $comparison = [
            'task' => 'Task 10 — Benchmarking and Bottleneck Analysis',
            'product_id' => $productId,
            'executed_at' => now()->toIso8601String(),
            'iterations' => max(count($slowSamples), count($optimizedSamples)),
            'before' => [
                'mode' => 'slow',
                'endpoint' => '/api/benchmark/sales-report/slow',
                'avg_response_time_ms' => $beforeAvgMs,
                'avg_db_queries' => $beforeDbQueries,
                'bottleneck_span' => $bottleneckSpan ?? 'sequential_order_product_lookups',
            ],
            'after' => [
                'mode' => 'optimized',
                'endpoint' => '/api/benchmark/sales-report/optimized',
                'avg_response_time_ms' => $afterAvgMs,
                'avg_db_queries' => $afterDbQueries,
            ],
            'improvement' => [
                'response_time_ms_saved' => $improvementMs,
                'response_time_percent_faster' => $improvementPercent,
                'db_queries_reduced' => $beforeDbQueries - $afterDbQueries,
                'db_queries_percent_fewer' => $queryReductionPercent,
            ],
            'explanation' => $this->buildExplanation(
                $beforeAvgMs,
                $afterAvgMs,
                $improvementPercent,
                $beforeDbQueries,
                $afterDbQueries,
                $bottleneckSpan,
            ),
        ];

        $this->benchmarkMetrics->recordComparison($comparison);

        return $comparison;
    }

    /** @param array<string, mixed> $comparison */
    public function writeReportFiles(array $comparison, string $output = 'both'): void
    {
        $jsonPath = (string) config('benchmarking.report_json_path');
        $markdownPath = (string) config('benchmarking.report_markdown_path');

        File::ensureDirectoryExists(dirname($jsonPath));
        File::ensureDirectoryExists(dirname($markdownPath));

        if (in_array($output, ['json', 'both'], true)) {
            File::put($jsonPath, json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (in_array($output, ['md', 'both'], true)) {
            File::put($markdownPath, $this->toMarkdown($comparison));
        }
    }

    public function persistRun(array $payload, string $mode, int $productId): BenchmarkRun
    {
        return BenchmarkRun::query()->create([
            'trace_id' => $payload['trace_id'],
            'mode' => $mode,
            'product_id' => $productId,
            'total_duration_ms' => $payload['total_duration_ms'],
            'db_queries' => $payload['db_queries'],
            'bottleneck_span' => $payload['bottleneck_span'] ?? null,
            'spans' => $payload['trace_spans'] ?? [],
        ]);
    }

    /** @param list<float|int> $values */
    private function average(array $samples, string $key): float
    {
        if ($samples === []) {
            return 0.0;
        }

        $sum = array_sum(array_map(fn (array $sample) => (float) ($sample[$key] ?? 0), $samples));

        return round($sum / count($samples), 3);
    }

    private function buildExplanation(
        float $beforeAvgMs,
        float $afterAvgMs,
        float $improvementPercent,
        int $beforeDbQueries,
        int $afterDbQueries,
        ?string $bottleneckSpan,
    ): string {
        $span = $bottleneckSpan ?? 'sequential_order_product_lookups';

        return sprintf(
            'Step 1–2: Benchmarking the slow sales report revealed bottleneck span "%s" (sequential DB round-trips per order). '
            .'Step 3: Replaced the loop with eager loading (`Order::with("product")`). '
            .'Step 4–5: Re-benchmark showed average response time drop from %.2f ms to %.2f ms (%.1f%% faster) and DB queries from %d to %d. '
            .'See trace_spans on the slow endpoint and GET /api/performance/stats for HTTP-level timings.',
            $span,
            $beforeAvgMs,
            $afterAvgMs,
            $improvementPercent,
            $beforeDbQueries,
            $afterDbQueries,
        );
    }

    /** @param array<string, mixed> $comparison */
    private function toMarkdown(array $comparison): string
    {
        $before = $comparison['before'];
        $after = $comparison['after'];
        $improvement = $comparison['improvement'];

        $md = "# Task 10 — Benchmark Comparison Report\n\n";
        $md .= 'Generated: '.now()->toDateTimeString()."\n\n";
        $md .= "## Before vs After\n\n";
        $md .= "| Metric | Before (slow) | After (optimized) | Improvement |\n";
        $md .= "|--------|---------------|---------------------|-------------|\n";
        $md .= sprintf(
            "| Avg response time (ms) | %s | %s | **%s%% faster** |\n",
            $before['avg_response_time_ms'],
            $after['avg_response_time_ms'],
            $improvement['response_time_percent_faster'],
        );
        $md .= sprintf(
            "| DB queries | %s | %s | **%s%% fewer** |\n",
            $before['avg_db_queries'],
            $after['avg_db_queries'],
            $improvement['db_queries_percent_fewer'],
        );
        $md .= sprintf(
            "| Bottleneck span | %s | reduced sequential lookups | fixed |\n\n",
            $before['bottleneck_span'],
        );

        $md .= "## What happened\n\n";
        $md .= $comparison['explanation']."\n";

        return $md;
    }
}
