<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inventory distributed locking (Task 7 — Session 7)
    |--------------------------------------------------------------------------
    |
    | Cluster-wide mutex via Redis before pessimistic DB purchase.
    | Separate from CACHE_STORE=database used by circuit breaker / semaphore.
    |
    */

    'lock_store' => env('INVENTORY_LOCK_STORE', 'redis'),

    'lock_prefix' => env('INVENTORY_LOCK_PREFIX', 'inventory:product:'),

    'lock_ttl_seconds' => (int) env('INVENTORY_LOCK_TTL', 10),

    'lock_block_seconds' => (int) env('INVENTORY_LOCK_BLOCK', 5),

    'demo_stock' => (int) env('INVENTORY_DEMO_STOCK', 10),

    'demo_burst_count' => (int) env('INVENTORY_DEMO_BURST', 10),

    'demo_optimistic_delay_ms' => (int) env('INVENTORY_DEMO_OPTIMISTIC_DELAY_MS', 50),

    'demo_request_delay_ms' => (int) env('INVENTORY_DEMO_REQUEST_DELAY_MS', 400),

    /*
    | Demo metrics — uses default CACHE_STORE so stats survive separate HTTP requests.
    */
    'metrics_cache_key' => env('INVENTORY_LOCK_METRICS_KEY', 'inventory:demo_metrics'),

    'metrics_store' => env('INVENTORY_LOCK_METRICS_STORE'),

];
