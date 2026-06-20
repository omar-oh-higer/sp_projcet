<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product catalog cache (Task 6 — distributed Redis Cache-Aside)
    |--------------------------------------------------------------------------
    |
    | Uses a dedicated cache store (default redis), separate from CACHE_STORE
    | used by circuit breaker / semaphore (database).
    |
    */

    'store' => env('PRODUCT_CACHE_STORE', 'redis'),

    'key_prefix' => env('PRODUCT_CACHE_KEY_PREFIX', 'product_catalog:'),

    'ttl_seconds' => (int) env('PRODUCT_CACHE_TTL', 300),

    'popular_product_ids' => array_map(
        'intval',
        array_filter(explode(',', env('PRODUCT_CACHE_POPULAR_IDS', '1,2,3')))
    ),

    'demo_request_delay_ms' => (int) env('PRODUCT_CACHE_DEMO_DELAY_MS', 400),

    /*
    | Demo metrics (hits/misses/lookup log) — uses default CACHE_STORE so stats
    | survive separate HTTP requests during /demo scenario (not in-memory).
    */
    'metrics_cache_key' => env('PRODUCT_CACHE_METRICS_KEY', 'product_catalog:demo_metrics'),

    'metrics_store' => env('PRODUCT_CACHE_METRICS_STORE'),

];
