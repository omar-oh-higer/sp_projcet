<?php

use App\Models\Product;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

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
