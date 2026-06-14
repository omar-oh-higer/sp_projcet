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

];
