<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task 4 — concurrent batch tally (Thread Pool alignment)
    |--------------------------------------------------------------------------
    |
    | chunk_size: orders per parallel chunk job (partial result row).
    | max_concurrent_chunks: Task 2-style cap on simultaneous chunk workers.
    |
    */

    'chunk_size' => (int) env('DAILY_SALES_TALLY_CHUNK_SIZE', 500),

    'max_concurrent_chunks' => (int) env('DAILY_SALES_TALLY_MAX_CONCURRENT_CHUNKS', 5),

    'semaphore_key' => 'daily-sales-tally-chunk-semaphore',

    'processing_mode_concurrent' => 'queued_batched_concurrent',

    /** Default order count when seeding from /demo (web). */
    'demo_seed_count' => (int) env('DAILY_SALES_TALLY_DEMO_SEED_COUNT', 2500),

    /** Max orders allowed per web seed request (same order of magnitude as bulk_orders.seed_max). */
    'demo_seed_max' => (int) env('DAILY_SALES_TALLY_DEMO_SEED_MAX', (int) env('BULK_ORDER_SEED_MAX', 500_000)),

    /** How many queue:work terminals you run in the demo (lecture UI). */
    'demo_worker_count' => (int) env('DAILY_SALES_TALLY_DEMO_WORKER_COUNT', 4),

    /** Seconds to sleep at start of each chunk job so /demo can show running workers (0 = off). */
    'demo_chunk_delay_seconds' => (float) env('DAILY_SALES_TALLY_DEMO_CHUNK_DELAY_SECONDS', 2),

];
