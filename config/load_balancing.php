<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Virtual backend pool (Task 5 — in-process simulation)
    |--------------------------------------------------------------------------
    |
    | Each entry is a logical server ID. Initial health comes from config;
    | POST /api/load/set-server-health can override via cache for demos.
    |
    */

    'backends' => [
        ['id' => 'server-1'],
        ['id' => 'server-2'],
        ['id' => 'server-3'],
    ],

    /** Before path: vertical scaling — all traffic pinned here (no balancer). */
    'single_target' => 'server-1',

    /** After path: sequential rotation among healthy backends. */
    'default_strategy' => 'round_robin',

    'cache_keys' => [
        'round_robin_index' => 'load_balancer:rr_index',
        'health_prefix' => 'load_balancer:health:',
    ],

];
