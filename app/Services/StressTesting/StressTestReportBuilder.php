<?php

namespace App\Services\StressTesting;

use Illuminate\Support\Facades\File;

/** Task 9: aggregates stress run metrics into JSON + Markdown reports. */
class StressTestReportBuilder
{
    public function __construct(
        private StressTestMetrics $stressTestMetrics,
    ) {}

    /**
     * @param  array<string, mixed>  $runMetrics
     * @param  array<string, mixed>  $integrity
     * @return array<string, mixed>
     */
    public function build(
        StressTestScenario $scenario,
        int $productId,
        int $quantity,
        int $users,
        string $baseUrl,
        array $runMetrics,
        array $integrity,
    ): array {
        $report = [
            'task' => 'Task 9 — Concurrent Stress Test',
            'scenario' => $scenario->key,
            'scenario_label' => $scenario->label,
            'endpoint' => $scenario->path,
            'transaction_mode' => $scenario->transactionMode,
            'base_url' => $baseUrl,
            'product_id' => $productId,
            'quantity' => $quantity,
            'concurrent_users' => $users,
            'executed_at' => now()->toIso8601String(),
            'total_requests' => $runMetrics['total_requests'],
            'success_requests' => $runMetrics['success_requests'],
            'failed_requests' => $runMetrics['failed_requests'],
            'rejected_requests' => $runMetrics['rejected_requests'],
            'connection_errors' => $runMetrics['connection_errors'],
            'average_response_time_ms' => $runMetrics['average_response_time_ms'],
            'average_server_response_time_ms' => $runMetrics['average_server_response_time_ms'],
            'pool_duration_ms' => $runMetrics['pool_duration_ms'],
            'system_crashed' => $runMetrics['system_crashed'],
            'data_integrity_pass' => $integrity['data_integrity_pass'],
            'integrity' => $integrity,
            'explanation' => $this->buildExplanation($scenario, $users, $runMetrics, $integrity),
        ];

        $this->stressTestMetrics->record($report);

        return $report;
    }

    /** @param list<array<string, mixed>> $reports */
    public function writeCombinedReport(array $reports, string $output = 'both'): void
    {
        $combined = [
            'task' => 'Task 9 — Concurrent Stress Test',
            'executed_at' => now()->toIso8601String(),
            'scenarios' => $reports,
        ];

        $jsonPath = (string) config('stress_testing.report_json_path');
        $markdownPath = (string) config('stress_testing.report_markdown_path');

        File::ensureDirectoryExists(dirname($jsonPath));
        File::ensureDirectoryExists(dirname($markdownPath));

        if (in_array($output, ['json', 'both'], true)) {
            File::put($jsonPath, json_encode($combined, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (in_array($output, ['md', 'both'], true)) {
            File::put($markdownPath, $this->toMarkdown($reports));
        }

        $this->stressTestMetrics->lastReport = $combined;
    }

    /** @param array<string, mixed> $runMetrics @param array<string, mixed> $integrity */
    private function buildExplanation(
        StressTestScenario $scenario,
        int $users,
        array $runMetrics,
        array $integrity,
    ): string {
        $lines = [];

        $lines[] = "Concurrent stress test fired {$users} simultaneous POST requests to {$scenario->path} ({$scenario->label}).";
        $lines[] = 'Success (HTTP 200) means a checkout completed. Rejected (HTTP 409) means insufficient stock under contention — expected when concurrent users exceed available inventory, not a server crash.';
        $lines[] = 'Failed requests include connection errors, timeouts, and unexpected HTTP errors (5xx or non-409 4xx).';

        if ($runMetrics['system_crashed']) {
            $lines[] = 'System crashed verdict: YES — too many connection errors or no HTTP responses (ensure php artisan serve is running).';
        } else {
            $lines[] = 'System crashed verdict: NO — the server responded to concurrent load.';
        }

        if ($integrity['data_integrity_pass']) {
            $lines[] = 'Data integrity: PASS — stock delta matches successful purchases and invariants held.';
        } else {
            $lines[] = 'Data integrity: FAIL — '.$integrity['integrity_notes'];
        }

        if ($scenario->key === 'unsafe') {
            $lines[] = 'The non-atomic path may leave orphan payments or inconsistent stock when many requests run in parallel because each step auto-commits separately.';
        } else {
            $lines[] = 'The ACID path wraps payment, inventory, and order creation in one DB transaction with row lock — concurrent requests serialize safely without losing data.';
        }

        $avg = $runMetrics['average_response_time_ms'];
        if ($avg !== null) {
            $lines[] = "Average response time: {$avg} ms (from X-Response-Time-Ms header when available).";
        }

        $lines[] = 'After the run, inspect GET /api/performance/stats for server-side latency aggregates (Session 8 observability).';

        return implode(' ', $lines);
    }

    /** @param list<array<string, mixed>> $reports */
    private function toMarkdown(array $reports): string
    {
        $md = "# Task 9 — Concurrent Stress Test Report\n\n";
        $md .= 'Generated: '.now()->toDateTimeString()."\n\n";

        foreach ($reports as $report) {
            $md .= "## {$report['scenario_label']}\n\n";
            $md .= "- **Endpoint:** `{$report['endpoint']}`\n";
            $md .= "- **Concurrent users:** {$report['concurrent_users']}\n";
            $md .= "- **Product ID:** {$report['product_id']}\n";
            $md .= "- **Quantity per request:** {$report['quantity']}\n\n";

            $md .= "| Metric | Value |\n";
            $md .= "|--------|-------|\n";
            $md .= "| Total Requests | {$report['total_requests']} |\n";
            $md .= "| Success Requests | {$report['success_requests']} |\n";
            $md .= "| Failed Requests | {$report['failed_requests']} |\n";
            $md .= "| Rejected (409) | {$report['rejected_requests']} |\n";
            $md .= "| Average Response Time (ms) | {$report['average_response_time_ms']} |\n";
            $md .= "| System Crashed | ".($report['system_crashed'] ? 'Yes' : 'No')." |\n";
            $md .= "| Data Integrity Pass | ".($report['data_integrity_pass'] ? 'Yes' : 'No')." |\n\n";

            $integrity = $report['integrity'];
            $md .= "**Integrity:** stock {$integrity['stock_before']} → {$integrity['stock_after']}, ";
            $md .= "sold expected {$integrity['units_sold_expected']}, actual {$integrity['units_sold_actual']}, ";
            $md .= "orphan payments {$integrity['orphan_payments']}.\n\n";

            $md .= "### What happened\n\n";
            $md .= $report['explanation']."\n\n";
            $md .= "---\n\n";
        }

        return $md;
    }
}
