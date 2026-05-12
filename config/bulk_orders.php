<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk order seeder (Task 4 demos)
    |--------------------------------------------------------------------------
    |
    | BULK_ORDER_SEED_COUNT is clamped to at most BULK_ORDER_SEED_MAX to avoid
    | accidental huge inserts. Raise the max in .env if you need more rows.
    |
    */

    'seed_count' => (int) env('BULK_ORDER_SEED_COUNT', 25_000),

    'seed_max' => (int) env('BULK_ORDER_SEED_MAX', 500_000),

    /*
    | Optional PHP memory limit while running POST /api/tally-daily-sales-wait only.
    | Helps huge local seeds; the queued path does not use this.
    */
    'tally_wait_memory_limit' => env('TALLY_WAIT_MEMORY_LIMIT', '512M'),

];
