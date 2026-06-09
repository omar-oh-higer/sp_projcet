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

];
