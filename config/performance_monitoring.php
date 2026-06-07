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

];
