<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Performance monitoring (AOP around-advice demo)
    |--------------------------------------------------------------------------
    */

    'enabled' => (bool) env('PERFORMANCE_MONITORING_ENABLED', true),

    'slow_threshold_ms' => (float) env('PERFORMANCE_SLOW_THRESHOLD_MS', 500),

    'expose_response_header' => (bool) env('PERFORMANCE_EXPOSE_RESPONSE_HEADER', true),

    'persist' => (bool) env('PERFORMANCE_MONITORING_PERSIST', true),

    'recent_limit' => (int) env('PERFORMANCE_MONITORING_RECENT_LIMIT', 50),

    'demo_request_delay_ms' => (int) env('PERFORMANCE_DEMO_DELAY_MS', 200),

    'excluded_path_prefixes' => [
        'api/performance',
    ],

    'demo_probe_endpoints' => [
        ['method' => 'POST', 'path' => '/api/buy-with-lock', 'label' => 'Purchase with lock (Task 1)'],
        ['method' => 'GET', 'path' => '/api/products/{id}/cached', 'label' => 'Cached product (Task 6)'],
        ['method' => 'GET', 'path' => '/api/benchmark/sales-report/slow', 'label' => 'Slow report (Task 10)'],
        ['method' => 'POST', 'path' => '/api/checkout/acid', 'label' => 'ACID checkout (Task 8)'],
    ],

];
