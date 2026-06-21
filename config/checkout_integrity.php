<?php

return [
    'payment_reference_prefix' => env('CHECKOUT_PAYMENT_PREFIX', 'pay_'),
    'currency_label' => env('CHECKOUT_CURRENCY_LABEL', 'USD'),
    'demo_stock' => (int) env('CHECKOUT_DEMO_STOCK', 10),
    'demo_request_delay_ms' => (int) env('CHECKOUT_DEMO_DELAY_MS', 400),
    'metrics_cache_key' => env('CHECKOUT_INTEGRITY_METRICS_KEY', 'checkout:demo_metrics'),
    'metrics_store' => env('CHECKOUT_INTEGRITY_METRICS_STORE'),
];
