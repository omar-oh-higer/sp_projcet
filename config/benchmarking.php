<?php

return [
    'sample_order_limit' => (int) env('BENCHMARK_SAMPLE_ORDERS', 20),
    'sequential_query_delay_ms' => (int) env('BENCHMARK_SEQUENTIAL_DELAY_MS', 5),
    'bottleneck_log_threshold_ms' => (int) env('BENCHMARK_BOTTLENECK_LOG_MS', 100),
    'default_iterations' => (int) env('BENCHMARK_ITERATIONS', 5),
    'default_base_url' => env('BENCHMARK_BASE_URL', 'http://127.0.0.1:8000'),
    'report_json_path' => storage_path('app/benchmark_reports/latest.json'),
    'report_markdown_path' => storage_path('docs/BENCHMARK_COMPARISON_REPORT.md'),
];
