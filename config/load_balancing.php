<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Virtual backend pool (Task 5 — in-process + real HTTP simulation)
    |--------------------------------------------------------------------------
    |
    | Each entry is a logical server ID with optional URL/port for multi-instance
    | demos. POST /api/load/set-server-health can override health via cache.
    |
    */

    'backends' => [
        [
            'id' => 'server-1',
            'url' => env('LOAD_NODE_1_URL', 'http://127.0.0.1:8000'),
            'port' => (int) env('LOAD_NODE_1_PORT', 8000),
        ],
        [
            'id' => 'server-2',
            'url' => env('LOAD_NODE_2_URL', 'http://127.0.0.1:8001'),
            'port' => (int) env('LOAD_NODE_2_PORT', 8001),
        ],
        [
            'id' => 'server-3',
            'url' => env('LOAD_NODE_3_URL', 'http://127.0.0.1:8002'),
            'port' => (int) env('LOAD_NODE_3_PORT', 8002),
        ],
    ],

    /** Identity of the current PHP process when running as a worker node. */
    'node_id' => env('APP_NODE_ID'),
    'node_port' => (int) env('APP_NODE_PORT', 8000),

    /** Gateway base URL (typically node 1) for load:multi-server CLI. */
    'gateway_url' => env('LOAD_GATEWAY_URL', 'http://127.0.0.1:8000'),

    /** Worker endpoint path (appended to backend URL). */
    'process_path' => '/api/load/process',

    /** HTTP timeout when forwarding to worker nodes (seconds). */
    'http_timeout' => (int) env('LOAD_HTTP_TIMEOUT', 5),

    /** Live probe timeout when checking /up on each node (seconds). */
    'probe_timeout' => (int) env('LOAD_PROBE_TIMEOUT', 1),

    /** Run live probe when fetching distribution stats (may add latency). */
    'probe_on_stats' => (bool) env('LOAD_PROBE_ON_STATS', true),

    /** Max Round Robin retries when a worker node is unreachable. */
    'gateway_max_retries' => (int) env('LOAD_GATEWAY_MAX_RETRIES', 3),

    /** Delay between demo UI requests so rotation is visible (milliseconds). */
    'demo_request_delay_ms' => (int) env('LOAD_DEMO_REQUEST_DELAY_MS', 300),

    /** Before path: vertical scaling — all traffic pinned here (no balancer). */
    'single_target' => 'server-1',

    /** After path: sequential rotation among healthy backends. */
    'default_strategy' => 'round_robin',

    'cache_keys' => [
        'round_robin_index' => 'load_balancer:rr_index',
        'health_prefix' => 'load_balancer:health:',
    ],

];
