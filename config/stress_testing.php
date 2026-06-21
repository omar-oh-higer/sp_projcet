<?php

return [
    'default_users' => (int) env('STRESS_TEST_USERS', 100),
    'default_base_url' => env('STRESS_TEST_BASE_URL', 'http://127.0.0.1:8000'),
    'default_quantity' => (int) env('STRESS_TEST_QUANTITY', 1),
    'request_timeout_seconds' => (int) env('STRESS_TEST_TIMEOUT', 30),
    'crash_connection_error_threshold' => (float) env('STRESS_TEST_CRASH_THRESHOLD', 0.10),
    'report_json_path' => storage_path('app/stress_reports/latest.json'),
    'report_markdown_path' => storage_path('docs/STRESS_TEST_REPORT.md'),
    'demo_users' => (int) env('STRESS_DEMO_USERS', 100),
    'demo_request_delay_ms' => (int) env('STRESS_DEMO_DELAY_MS', 600),
    'demo_stock' => (int) env('STRESS_DEMO_STOCK', 10),
    'demo_users_max' => (int) env('STRESS_DEMO_USERS_MAX', 100),
    'metrics_cache_key' => env('STRESS_METRICS_KEY', 'stress:demo_metrics'),
    'metrics_store' => env('STRESS_METRICS_STORE'),
    'demo_run_lock_key' => env('STRESS_DEMO_RUN_LOCK_KEY', 'stress:demo_run_active'),
    'demo_run_lock_ttl_seconds' => (int) env('STRESS_DEMO_RUN_LOCK_TTL', 300),
    'demo_run_stale_seconds' => (int) env('STRESS_DEMO_RUN_STALE_SECONDS', 45),
    'unsafe_use_process_pool' => filter_var(env('STRESS_UNSAFE_USE_PROCESS_POOL', true), FILTER_VALIDATE_BOOL),
    'unsafe_race_window_ms' => (int) env('STRESS_UNSAFE_RACE_WINDOW_MS', 30),
];
