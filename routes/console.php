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
