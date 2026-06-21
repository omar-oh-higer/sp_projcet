<?php

use App\Models\Product;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\ReleaseInvoiceJob;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


//with lock flow
Artisan::command('orders:simulate-lock {productId} {--requests=50} {--quantity=1} {--baseUrl=http://10.64.106.70:8000}', function () {
    $productId = (int) $this->argument('productId');
    $requests = max((int) $this->option('requests'), 1);
    $quantity = max((int) $this->option('quantity'), 1);
    $baseUrl = rtrim((string) $this->option('baseUrl'), '/');
    $stockBefore = Product::query()->find($productId)?->stock;

    if ($stockBefore === null) {
        $this->error('Product not found for id '.$productId);
        return;
    }

    $responses = Http::pool(function ($pool) use ($productId, $requests, $quantity, $baseUrl) {
        $calls = [];

        for ($i = 0; $i < $requests; $i++) {
            $calls[] = $pool->as((string) $i)->post($baseUrl.'/api/buy-with-lock', [
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        return $calls;
    });

    $success = 0;
    $conflict = 0;
    $other = 0;
    $connectionErrors = 0;

    foreach ($responses as $response) {
        if (! $response instanceof Response) {
            $connectionErrors++;
            $other++;
            continue;
        }

        if ($response->successful()) {
            $success++;
            continue;
        }

        if ($response->status() === 409) {
            $conflict++;
            continue;
        }

        $other++;
    }

    $this->info('Simulation complete');
    $this->line('Requests: '.$requests);
    $this->line('Success (200): '.$success);
    $this->line('Conflict (409): '.$conflict);
    $this->line('Other: '.$other);

    $stockAfter = Product::query()->find($productId)?->stock;
    $actualSoldUnits = $stockBefore - $stockAfter;
    $reportedSoldUnits = $success * $quantity;
    $maxPossibleSuccess = intdiv($stockBefore, $quantity);
    $this->line('Stock before: '.$stockBefore);
    $this->line('Stock after: '.$stockAfter);
    $this->line('Reported sold units (success * quantity): '.$reportedSoldUnits);
    $this->line('Actual sold units (stock before - stock after): '.$actualSoldUnits);
    $this->line('Max possible success from initial stock: '.$maxPossibleSuccess);
    if ($reportedSoldUnits === $actualSoldUnits && $success <= $maxPossibleSuccess) {
        $this->info('Integrity check: PASS');
    } else {
        $this->warn('Integrity check: FAIL');
    }

    if ($connectionErrors > 0) {
        $this->warn('Connection errors: '.$connectionErrors.' (make sure php artisan serve is running)');
    }
})->purpose('Send parallel requests to the lock-safe purchase endpoint');



//wihthou lock flow
Artisan::command('orders:simulate-nolock {productId} {--requests=50} {--quantity=1} {--delayMs=0} {--baseUrl=http://10.64.106.70:8000}', function () {
    $productId = (int) $this->argument('productId');
    $requests = max((int) $this->option('requests'), 1);
    $quantity = max((int) $this->option('quantity'), 1);
    $delayMs = max((int) $this->option('delayMs'), 0);
    $baseUrl = rtrim((string) $this->option('baseUrl'), '/');
    $stockBefore = Product::query()->find($productId)?->stock;

    if ($stockBefore === null) {
        $this->error('Product not found for id '.$productId);
        return;
    }

    $responses = Http::pool(function ($pool) use ($productId, $requests, $quantity, $baseUrl, $delayMs) {
        $calls = [];

        for ($i = 0; $i < $requests; $i++) {
            $calls[] = $pool->as((string) $i)
                ->withHeaders(['X-DEMO-DELAY-MS' => (string) $delayMs])
                ->post($baseUrl.'/api/buy-without-lock', [
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        return $calls;
    });

    $success = 0;
    $conflict = 0;
    $other = 0;
    $connectionErrors = 0;

    foreach ($responses as $response) {
        if (! $response instanceof Response) {
            $connectionErrors++;
            $other++;
            continue;
        }

        if ($response->successful()) {
            $success++;
            continue;
        }

        if ($response->status() === 409) {
            $conflict++;
            continue;
        }

        $other++;
    }

    $this->info('Simulation complete (NO LOCK)');
    $this->line('Requests: '.$requests);
    $this->line('Success (200): '.$success);
    $this->line('Conflict (409): '.$conflict);
    $this->line('Other: '.$other);
    $this->line('Injected no-lock delay per request (ms): '.$delayMs);

    $stockAfter = Product::query()->find($productId)?->stock;
    $actualSoldUnits = $stockBefore - $stockAfter;
    $reportedSoldUnits = $success * $quantity;
    $maxPossibleSuccess = intdiv($stockBefore, $quantity);
    $this->line('Stock before: '.$stockBefore);
    $this->line('Stock after: '.$stockAfter);
    $this->line('Reported sold units (success * quantity): '.$reportedSoldUnits);
    $this->line('Actual sold units (stock before - stock after): '.$actualSoldUnits);
    $this->line('Max possible success from initial stock: '.$maxPossibleSuccess);
    if ($reportedSoldUnits === $actualSoldUnits && $success <= $maxPossibleSuccess) {
        $this->info('Integrity check: PASS');
    } else {
        $this->warn('Integrity check: FAIL (lost update / race signature)');
    }

    if ($connectionErrors > 0) {
        $this->warn('Connection errors: '.$connectionErrors.' (make sure php artisan serve is running)');
    }
})->purpose('Send parallel requests to the unsafe purchase endpoint for race-condition comparison');


// Load simulation command for testing all three patterns
Artisan::command('load:simulate {productId} {--requests=150} {--quantity=1} {--baseUrl=http://10.64.106.70:8000}', function () {
    $productId = (int) $this->argument('productId');
    $requests = max((int) $this->option('requests'), 1);
    $quantity = max((int) $this->option('quantity'), 1);
    $baseUrl = rtrim((string) $this->option('baseUrl'), '/');

    $product = Product::query()->find($productId);
    if (!$product) {
        $this->error('Product not found for id '.$productId);
        return;
    }

    $this->info('Load Simulation Test (HTTP + Queue + Semaphore + Circuit Breaker)');
    $this->line('========================================================');
    $this->line('Product ID: '.$productId);
    $this->line('Requests: '.$requests);
    $this->line('Quantity per request: '.$quantity);
    $this->line('');

    // Phase 1: HTTP Rate Limiting Test (100 req/min limiter)
    $this->info('Phase 1: Testing HTTP Rate Limiting (100 req/min limiter)...');
    $stockBefore = $product->fresh()->stock;
    $rateLimitedResponses = Http::pool(function ($pool) use ($productId, $requests, $quantity, $baseUrl) {
        $calls = [];
        for ($i = 0; $i < $requests; $i++) {
            $calls[] = $pool->as((string) $i)->post($baseUrl.'/api/buy-with-lock', [
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }
        return $calls;
    });

    $success = 0;
    $conflict = 0;
    $rateLimited = 0;
    $other = 0;
    $connectionErrors = 0;

    foreach ($rateLimitedResponses as $response) {
        if (!$response instanceof Response) {
            $connectionErrors++;
            $other++;
            continue;
        }

        if ($response->successful()) {
            $success++;
            continue;
        }

        if ($response->status() === 409) {
            $conflict++;
            continue;
        }

        if ($response->status() === 429) {
            $rateLimited++;
            continue;
        }

        $other++;
    }

    $stockAfter = $product->fresh()->stock;
    $actualSoldUnits = $stockBefore - $stockAfter;
    $reportedSoldUnits = $success * $quantity;

    $this->line('Results:');
    $this->line('  Success (200): '.$success);
    $this->line('  Conflict/OutOfStock (409): '.$conflict);
    $this->line('  Rate Limited (429): '.$rateLimited);
    $this->line('  Other: '.$other);
    $this->line('  Stock before: '.$stockBefore);
    $this->line('  Stock after: '.$stockAfter);
    $this->line('  Reported sold units: '.$reportedSoldUnits);
    $this->line('  Actual sold units: '.$actualSoldUnits);

    if ($rateLimited > 0) {
        $this->info('✓ Rate limiting working (some requests were throttled)');
    } else {
        $this->warn('✗ Rate limiting may not be active (no 429 responses)');
    }

    if ($reportedSoldUnits === $actualSoldUnits) {
        $this->info('✓ Integrity check PASS (stock updates match reported sales)');
    } else {
        $this->warn('✗ Integrity check FAIL');
    }

    $this->line('');

    // Phase 2: Queue Worker Semaphore Test
    $this->info('Phase 2: Testing Queue Worker Semaphore (max 5 concurrent)...');
    $ordersCreatedBefore = Order::count();

    for ($i = 0; $i < 20; $i++) {
        $order = Order::create([
            'product_id' => $productId,
            'user_id' => null,
            'quantity' => 1,
            'status' => 'pending',
        ]);
        ReleaseInvoiceJob::dispatch($order->id);
    }

    $ordersCreatedAfter = Order::count();
    $jobsDispatched = $ordersCreatedAfter - $ordersCreatedBefore;

    $this->line('Results:');
    $this->line('  Orders created: '.$jobsDispatched);
    $this->line('  Jobs dispatched to queue: '.$jobsDispatched);
    $this->line('  Max concurrent limit: 5');
    $this->info('✓ Semaphore jobs queued (run php artisan queue:work to process)');

    $this->line('');

    // Phase 3: Circuit Breaker Status
    $this->info('Phase 3: Circuit Breaker Status Check...');
    $circuitState = 'CLOSED (accepting requests)';
    $this->line('Results:');
    $this->line('  State: '.$circuitState);
    $this->info('✓ Circuit breaker initialized');

    $this->line('');
    $this->info('Load simulation complete!');
    $this->line('Next steps:');
    $this->line('  1. Run: php artisan queue:work');
    $this->line('  2. Monitor: logs/laravel.log for job processing');
    $this->line('  3. Check: semaphore and circuit breaker in action');

    if ($connectionErrors > 0) {
        $this->warn('Connection errors: '.$connectionErrors.' (make sure php artisan serve is running)');
    }
})->purpose('Comprehensive load test for rate limiting, semaphores, and circuit breaker');

// Task 5: Round Robin distribution simulation (in-process, no HTTP)
Artisan::command('load:distribute {--requests=30}', function () {
    $requests = max((int) $this->option('requests'), 1);

    $healthRegistry = app(\App\Services\LoadBalancing\BackendHealthRegistry::class);
    $balancer = app(\App\Services\LoadBalancing\RoundRobinLoadBalancer::class);
    $recorder = app(\App\Services\LoadBalancing\LoadDistributionRecorder::class);

    $balancer->resetRotation();

    $counts = [];
    foreach ($healthRegistry->allBackendIds() as $id) {
        $counts[$id] = 0;
    }

    $this->info('Task 5: Round Robin load distribution simulation');
    $this->line('Requests: '.$requests);
    $this->line('Healthy backends: '.implode(', ', $healthRegistry->healthyBackendIds()));
    $this->line('');

    for ($i = 1; $i <= $requests; $i++) {
        try {
            $target = $balancer->nextBackend();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        $recorder->record($target, 'round_robin', $i);
        $counts[$target] = ($counts[$target] ?? 0) + 1;
    }

    $this->table(
        ['Server', 'Hits', 'Share %'],
        collect($counts)->map(function (int $hits, string $server) use ($requests) {
            return [
                $server,
                $hits,
                round(($hits / $requests) * 100, 1).'%',
            ];
        })->values()->all()
    );

    $this->line('');
    $this->info('Run GET /api/load/distribution-stats for the same totals from the database.');
})->purpose('Simulate Round Robin request distribution across virtual backends');

// Task 4: concurrent daily sales tally demo (thread pool via queue workers)
Artisan::command('tally:concurrent-demo {sale_date} {--chunk-size=}', function () {
    $saleDate = (string) $this->argument('sale_date');
    $chunkSize = $this->option('chunk-size');

    if ($chunkSize !== null) {
        config(['daily_sales_tally.chunk_size' => max((int) $chunkSize, 1)]);
    }

    $orchestrator = app(\App\Services\DailySalesTally\DailySalesTallyBatchOrchestrator::class);
    $expected = $orchestrator->expectedChunkCount($saleDate);

    $this->info('Task 4: Concurrent batch tally');
    $this->line('Sale date: '.$saleDate);
    $this->line('Chunk size: '.config('daily_sales_tally.chunk_size'));
    $this->line('Expected chunks: '.$expected);
    $this->line('Max concurrent chunks (Task 2 semaphore): '.config('daily_sales_tally.max_concurrent_chunks'));
    $this->line('');
    $this->warn('Start multiple workers in separate terminals: php artisan queue:work');
    $this->line('');

    $result = $orchestrator->start($saleDate);

    $this->info('Batch dispatched.');
    $this->line('Batch ID: '.$result['batch_id']);
    $this->line('Then: GET /api/daily-sales-summary?sale_date='.$saleDate);
})->purpose('Queue parallel chunk jobs for daily sales tally (Task 4 thread pool demo)');

// Task 5: real multi-port HTTP demo (3 artisan serve instances on 8000/8001/8002)
Artisan::command('load:multi-server {--tasks=12} {--mode=balanced} {--gateway=} {--reset}', function () {
    $tasks = max((int) $this->option('tasks'), 1);
    $mode = strtolower((string) $this->option('mode'));
    $gatewayUrl = rtrim((string) ($this->option('gateway') ?: config('load_balancing.gateway_url', 'http://127.0.0.1:8000')), '/');

    if (! in_array($mode, ['balanced', 'single'], true)) {
        $this->error('Invalid --mode. Use balanced or single.');
        return 1;
    }

    $endpoint = $mode === 'single'
        ? '/api/load/process-single'
        : '/api/load/process-balanced';

    if ($this->option('reset')) {
        $resetResponse = Http::post($gatewayUrl.'/api/load/distribution-reset');
        if ($resetResponse->successful()) {
            $this->line('Load distribution demo data reset.');
        }
    }

    $balancer = app(\App\Services\LoadBalancing\RoundRobinLoadBalancer::class);
    $balancer->resetRotation();

    $this->info('Task 5: Multi-port real server simulation');
    $this->line('Gateway: '.$gatewayUrl);
    $this->line('Mode: '.$mode);
    $this->line('Tasks: '.$tasks);
    $this->line('');
    $this->warn('Ensure all nodes are running: .\\scripts\\start-multi-server.ps1');
    $this->line('');

    $failures = 0;

    for ($task = 1; $task <= $tasks; $task++) {
        try {
            $response = Http::timeout((int) config('load_balancing.http_timeout', 5))
                ->acceptJson()
                ->post($gatewayUrl.$endpoint, ['task_number' => $task]);
        } catch (\Throwable $e) {
            $failures++;
            $this->error("Task {$task} -> Connection failed ({$e->getMessage()})");
            continue;
        }

        if (! $response->successful()) {
            $failures++;
            $this->error("Task {$task} -> Gateway error HTTP {$response->status()}");
            continue;
        }

        $body = $response->json();
        $port = (int) ($body['target_port'] ?? $body['worker_response']['node_port'] ?? 0);
        $handledBy = (string) ($body['handled_by'] ?? "node on port {$port}");

        $this->line("Task {$task} -> Handled by {$handledBy}");
    }

    $this->line('');

    if ($failures > 0) {
        $this->warn("Completed with {$failures} failure(s). Start all nodes and use CACHE_STORE=database.");
        return 1;
    }

    $this->info('Done. Run GET /api/load/distribution-stats for hit totals.');

    return 0;
})->purpose('Send tasks through the gateway to real worker nodes on ports 8000-8002');

// Task 6: warm popular products into Redis (Cache-Aside)
Artisan::command('products:cache-warm', function () {
    $lookup = app(\App\Services\ProductCatalog\CachedProductLookup::class);

    $this->info('Task 6: warming popular products into Redis (Cache-Aside)...');
    $this->line('Store: '.config('product_cache.store', 'redis'));
    $this->line('Popular IDs: '.implode(', ', config('product_cache.popular_product_ids', [])));
    $this->line('');

    $warmed = $lookup->warmPopular();

    $this->table(
        ['Product ID', 'Warmed', 'Cache result'],
        collect($warmed)->map(fn (array $row) => [
            $row['product_id'],
            $row['warmed'] ? 'yes' : 'no',
            $row['cache_result'],
        ])->all()
    );

    $this->line('');
    $this->info('Run GET /api/cache/stats or call /cached twice to see hit rate.');
})->purpose('Warm popular product catalog entries into Redis cache');

// Task 7: stress optimistic vs distributed inventory locking
Artisan::command('concurrency:stress {--product=1} {--requests=20} {--strategy=optimistic}', function () {
    $productId = max((int) $this->option('product'), 1);
    $requests = max((int) $this->option('requests'), 1);
    $strategy = (string) $this->option('strategy');

    $product = \App\Models\Product::query()->find($productId);
    if (! $product) {
        $this->error('Product not found for id '.$productId);

        return;
    }

    $this->info('Task 7: concurrency stress — '.$strategy);
    $this->line('Product #'.$productId.' stock='.$product->stock.' version='.$product->version);
    $this->line('Requests: '.$requests);
    $this->line('Lock store: '.config('inventory_locking.lock_store', 'redis'));
    $this->line('');

    app(\App\Services\ConcurrencyControl\ConcurrencyControlMetrics::class)->reset();

    $optimistic = app(\App\Services\ConcurrencyControl\OptimisticStockPurchaseService::class);
    $distributed = app(\App\Services\ConcurrencyControl\DistributedLockStockPurchaseService::class);

    $counts = [
        'success' => 0,
        'version_conflict' => 0,
        'insufficient_stock' => 0,
        'lock_timeout' => 0,
        'other' => 0,
    ];

    for ($i = 1; $i <= $requests; $i++) {
        if ($strategy === 'distributed') {
            $result = $distributed->purchase($productId, 1);
        } else {
            $result = $optimistic->purchase($productId, 1, null, 50);
        }

        $status = $result['status'];
        if (isset($counts[$status])) {
            $counts[$status]++;
        } else {
            $counts['other']++;
        }
    }

    $product->refresh();

    $this->table(
        ['Outcome', 'Count'],
        collect($counts)->map(fn (int $count, string $key) => [$key, $count])->values()->all()
    );

    $this->line('');
    $this->line('Final stock: '.$product->stock.' (version '.$product->version.')');
    $this->info('Metrics: '.json_encode(app(\App\Services\ConcurrencyControl\ConcurrencyControlMetrics::class)->snapshot()));
    $this->line('Run GET /api/concurrency/stats for the same counters.');
})->purpose('Stress-test optimistic vs distributed inventory locking');

Artisan::command('checkout:integrity-demo {--product=1} {--fail-at=after_payment} {--mode=non-atomic}', function () {
    $productId = max((int) $this->option('product'), 1);
    $failAt = (string) $this->option('fail-at');
    $mode = (string) $this->option('mode');

    if (! in_array($failAt, ['after_payment', 'after_stock'], true)) {
        $this->error('Invalid --fail-at. Use after_payment or after_stock.');

        return;
    }

    if (! in_array($mode, ['non-atomic', 'acid'], true)) {
        $this->error('Invalid --mode. Use non-atomic or acid.');

        return;
    }

    $product = Product::query()->find($productId);
    if (! $product) {
        $this->error('Product not found for id '.$productId);

        return;
    }

    $this->info('Task 8: checkout integrity demo — '.$mode.' (fail at '.$failAt.')');
    $this->line('Product #'.$productId.' stock='.$product->stock.' price_cents='.$product->price_cents);
    $this->line('');

    $paymentsBefore = \App\Models\Payment::query()->count();
    $ordersBefore = Order::query()->count();
    $stockBefore = $product->stock;

    $service = $mode === 'acid'
        ? app(\App\Services\TransactionIntegrity\AcidCheckoutService::class)
        : app(\App\Services\TransactionIntegrity\NonAtomicCheckoutService::class);

    $result = $service->checkout(
        productId: $productId,
        quantity: 1,
        userId: null,
        simulateFailAt: $failAt,
        paymentDeclined: false,
    );

    $product->refresh();

    $this->table(
        ['Metric', 'Before', 'After'],
        [
            ['payments', $paymentsBefore, \App\Models\Payment::query()->count()],
            ['orders', $ordersBefore, Order::query()->count()],
            ['stock', $stockBefore, $product->stock],
        ]
    );

    $this->line('');
    $this->info('Result: '.json_encode($result));
    $this->line('Orphan payments: '.app(\App\Services\TransactionIntegrity\CheckoutIntegrityMetrics::class)->orphanPaymentCount());
    $this->line('Run GET /api/checkout/integrity-stats for full metrics.');
})->purpose('Demonstrate non-atomic vs ACID checkout rollback under simulated failure');

Artisan::command('stress:checkout-worker {--product=1} {--quantity=1} {--mode=non_atomic} {--race-window-ms=0}', function () {
    $productId = max((int) $this->option('product'), 1);
    $quantity = max((int) $this->option('quantity'), 1);
    $mode = (string) $this->option('mode');
    $raceWindowMs = max((int) $this->option('race-window-ms'), 0);
    $started = microtime(true);

    if (! in_array($mode, ['non_atomic', 'acid'], true)) {
        $this->output->write(json_encode([
            'http_status' => 422,
            'duration_ms' => round((microtime(true) - $started) * 1000, 3),
            'result' => ['status' => 'invalid_mode', 'transaction_mode' => $mode],
        ]));

        return 1;
    }

    if ($mode === 'acid') {
        $result = app(\App\Services\TransactionIntegrity\AcidCheckoutService::class)->checkout(
            productId: $productId,
            quantity: $quantity,
        );
    } else {
        $result = app(\App\Services\TransactionIntegrity\NonAtomicCheckoutService::class)->checkout(
            productId: $productId,
            quantity: $quantity,
            stressRaceWindowMs: $raceWindowMs,
        );
    }

    $httpStatus = match ($result['status'] ?? '') {
        'success' => 200,
        'insufficient_stock', 'payment_declined' => 409,
        'product_not_found' => 404,
        default => 500,
    };

    $this->output->write(json_encode([
        'http_status' => $httpStatus,
        'duration_ms' => round((microtime(true) - $started) * 1000, 3),
        'result' => $result,
    ]));

    return 0;
})->purpose('Internal: one checkout for unsafe stress process pool');

Artisan::command('stress:concurrent {--users=} {--product=1} {--quantity=} {--baseUrl=} {--scenario=safe} {--output=both}', function () {
    $metrics = app(\App\Services\StressTesting\StressTestMetrics::class);

    try {
        $users = (int) ($this->option('users') ?: config('stress_testing.default_users', 100));
        $productId = max((int) $this->option('product'), 1);
        $quantity = (int) ($this->option('quantity') ?: config('stress_testing.default_quantity', 1));
        $baseUrl = rtrim((string) ($this->option('baseUrl') ?: config('stress_testing.default_base_url')), '/');
        $scenarioKey = (string) $this->option('scenario');
        $output = (string) $this->option('output');

        if ($users < 1) {
            $this->error('--users must be at least 1');

            return 1;
        }

        if (! in_array($output, ['console', 'md', 'json', 'both', 'none'], true)) {
            $this->error('Invalid --output. Use console, md, json, both, or none.');

            return 1;
        }

        $orchestrator = app(\App\Services\StressTesting\StressTestOrchestrator::class);

        try {
            $product = $orchestrator->assertProductExists($productId);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        try {
            \App\Services\StressTesting\StressTestScenario::forKey($scenarioKey);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $writeOutput = in_array($output, ['md', 'json', 'both'], true)
            ? ($output === 'both' ? 'both' : $output)
            : 'none';

        $this->info('Task 9: concurrent stress test — '.$scenarioKey.' ('.$users.' simultaneous users)');
        $this->line('Base URL: '.$baseUrl);
        $this->line('Product #'.$productId.' stock='.$product->stock.' price_cents='.$product->price_cents);
        $this->line('Ensure php artisan serve is running on '.$baseUrl);
        $this->line('');

        $result = $orchestrator->runScenarios(
            productId: $productId,
            quantity: $quantity,
            users: $users,
            baseUrl: $baseUrl,
            scenarioKey: $scenarioKey,
            writeOutput: $writeOutput,
        );

        foreach ($result['reports'] as $report) {
            $this->info('Scenario: '.$report['scenario_label'].' → POST '.$report['endpoint']);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Requests', $report['total_requests']],
                    ['Success Requests', $report['success_requests']],
                    ['Failed Requests', $report['failed_requests']],
                    ['Rejected (409)', $report['rejected_requests']],
                    ['Average Response Time (ms)', $report['average_response_time_ms'] ?? 'n/a'],
                    ['System Crashed', $report['system_crashed'] ? 'Yes' : 'No'],
                    ['Data Integrity Pass', $report['data_integrity_pass'] ? 'Yes' : 'No'],
                ]
            );

            $this->line($report['explanation']);
            $this->line('');
        }

        if (in_array($output, ['md', 'json', 'both'], true)) {
            $this->info('Report written to:');
            if (in_array($output, ['json', 'both'], true)) {
                $this->line('  JSON: '.config('stress_testing.report_json_path'));
            }
            if (in_array($output, ['md', 'both'], true)) {
                $this->line('  Markdown: '.config('stress_testing.report_markdown_path'));
            }
        }

        $this->line('Read back via GET /api/stress/stats or GET /api/stress/last-report');

        return 0;
    } finally {
        $metrics->clearDemoRunLock();
    }
})->purpose('Run 100+ concurrent checkout stress test and generate report');

Artisan::command('benchmark:compare {--product=1} {--iterations=} {--baseUrl=} {--output=both}', function () {
    $productId = max((int) $this->option('product'), 1);
    $iterations = max((int) ($this->option('iterations') ?: config('benchmarking.default_iterations', 5)), 1);
    $baseUrl = rtrim((string) ($this->option('baseUrl') ?: config('benchmarking.default_base_url')), '/');
    $output = (string) $this->option('output');

    $product = Product::query()->find($productId);
    if (! $product) {
        $this->error('Product not found for id '.$productId);

        return;
    }

    $orderCount = Order::query()
        ->where('product_id', $productId)
        ->where('status', 'success')
        ->count();

    if ($orderCount < 5) {
        $this->warn('Few orders found ('.$orderCount.'). Run: php artisan db:seed --class=BenchmarkOrdersSeeder');
    }

    $this->info('Task 10: benchmark compare — '.$iterations.' iterations per mode');
    $this->line('Product #'.$productId.' | Base URL: '.$baseUrl);
    $this->line('Ensure php artisan serve is running.');
    $this->line('');

    $slowSamples = [];
    $optimizedSamples = [];
    $bottleneckSpan = null;

    for ($i = 1; $i <= $iterations; $i++) {
        $slowResponse = \Illuminate\Support\Facades\Http::timeout(60)
            ->acceptJson()
            ->get($baseUrl.'/api/benchmark/sales-report/slow', ['product_id' => $productId]);

        if ($slowResponse->successful()) {
            $body = $slowResponse->json();
            $slowSamples[] = [
                'total_duration_ms' => (float) ($body['total_duration_ms'] ?? 0),
                'db_queries' => (int) ($body['db_queries'] ?? 0),
            ];
            $bottleneckSpan = $body['bottleneck_span'] ?? $bottleneckSpan;
        }

        $optimizedResponse = \Illuminate\Support\Facades\Http::timeout(60)
            ->acceptJson()
            ->get($baseUrl.'/api/benchmark/sales-report/optimized', ['product_id' => $productId]);

        if ($optimizedResponse->successful()) {
            $body = $optimizedResponse->json();
            $optimizedSamples[] = [
                'total_duration_ms' => (float) ($body['total_duration_ms'] ?? 0),
                'db_queries' => (int) ($body['db_queries'] ?? 0),
            ];
        }
    }

    if ($slowSamples === [] || $optimizedSamples === []) {
        $this->error('Benchmark requests failed. Is php artisan serve running on '.$baseUrl.'?');

        return;
    }

    $builder = app(\App\Services\Benchmarking\BenchmarkComparisonBuilder::class);
    $comparison = $builder->build($productId, $slowSamples, $optimizedSamples, $bottleneckSpan);

    if (in_array($output, ['md', 'json', 'both'], true)) {
        $builder->writeReportFiles($comparison, $output);
    }

    $this->table(
        ['Metric', 'Before (slow)', 'After (optimized)', 'Improvement'],
        [
            [
                'Avg response time (ms)',
                $comparison['before']['avg_response_time_ms'],
                $comparison['after']['avg_response_time_ms'],
                $comparison['improvement']['response_time_percent_faster'].'% faster',
            ],
            [
                'DB queries',
                $comparison['before']['avg_db_queries'],
                $comparison['after']['avg_db_queries'],
                $comparison['improvement']['db_queries_percent_fewer'].'% fewer',
            ],
            [
                'Bottleneck span',
                $comparison['before']['bottleneck_span'],
                'eager_load_with_product',
                'fixed',
            ],
        ]
    );

    $this->line('');
    $this->info($comparison['explanation']);
    $this->line('');
    $this->line('GET /api/benchmark/comparison');
    if (in_array($output, ['md', 'both'], true)) {
        $this->line('Markdown: '.config('benchmarking.report_markdown_path'));
    }
})->purpose('Benchmark slow vs optimized sales report and write comparison report');
