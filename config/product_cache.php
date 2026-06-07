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

];
