<?php

use App\Http\Controllers\BenchmarkController;
use App\Http\Controllers\StressTestController;
use App\Http\Controllers\CheckoutIntegrityController;
use App\Http\Controllers\DailySalesTallyController;
use App\Http\Controllers\InventoryConcurrencyController;
use App\Http\Controllers\LoadDistributionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PerformanceMonitoringController;
use App\Http\Controllers\ProductCatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:purchases')->group(function () {
    Route::post('/buy-without-lock', [OrderController::class, 'buyWithoutLock']);

    Route::middleware('circuit.breaker')->group(function () {
        Route::post('/buy-with-lock', [OrderController::class, 'buyWithLock']);
        Route::post('/buy-with-lock-wait-invoice', [OrderController::class, 'buyWithLockWaitForInvoice']);
        Route::post('/buy-distributed-lock', [InventoryConcurrencyController::class, 'buyDistributedLock']);
    });
    });

    Route::post('/buy-optimistic', [InventoryConcurrencyController::class, 'buyOptimistic']);
    Route::get('/concurrency/stats', [InventoryConcurrencyController::class, 'stats']);
    Route::post('/concurrency/reset', [InventoryConcurrencyController::class, 'reset']);
    Route::post('/concurrency/demo-reset', [InventoryConcurrencyController::class, 'demoReset']);
    Route::post('/concurrency/demo-stress', [InventoryConcurrencyController::class, 'demoStress']);

    Route::post('/checkout/non-atomic', [CheckoutIntegrityController::class, 'checkoutNonAtomic']);
    Route::post('/checkout/acid', [CheckoutIntegrityController::class, 'checkoutAcid']);
    Route::get('/checkout/integrity-stats', [CheckoutIntegrityController::class, 'stats']);
    Route::post('/checkout/integrity-reset', [CheckoutIntegrityController::class, 'reset']);
    Route::post('/checkout/demo-reset', [CheckoutIntegrityController::class, 'demoReset']);

    Route::get('/stress/last-report', [StressTestController::class, 'lastReport']);
    Route::get('/stress/stats', [StressTestController::class, 'stats']);
    Route::post('/stress/reset', [StressTestController::class, 'reset']);
    Route::post('/stress/demo-reset', [StressTestController::class, 'demoReset']);
    Route::post('/stress/demo-run', [StressTestController::class, 'demoRun']);

    Route::get('/benchmark/stats', [BenchmarkController::class, 'stats']);
    Route::get('/benchmark/sales-report/slow', [BenchmarkController::class, 'salesReportSlow']);
    Route::get('/benchmark/sales-report/optimized', [BenchmarkController::class, 'salesReportOptimized']);
    Route::get('/benchmark/comparison', [BenchmarkController::class, 'comparison']);
    Route::get('/benchmark/traces', [BenchmarkController::class, 'traces']);
    Route::post('/benchmark/reset', [BenchmarkController::class, 'reset']);
    Route::post('/benchmark/demo-reset', [BenchmarkController::class, 'demoReset']);
    Route::post('/benchmark/demo-run', [BenchmarkController::class, 'demoRun']);

    Route::post('/tally-daily-sales-wait', [DailySalesTallyController::class, 'tallyWait']);
    Route::post('/tally-daily-sales-queued', [DailySalesTallyController::class, 'tallyQueued']);
    Route::get('/daily-sales-summary', [DailySalesTallyController::class, 'showSummary']);
    Route::post('/tally-demo/seed-orders', [DailySalesTallyController::class, 'seedDemoOrders']);
    Route::get('/tally-demo/batch-status', [DailySalesTallyController::class, 'batchStatus']);

    Route::post('/load/route-single', [LoadDistributionController::class, 'routeSingle']);
    Route::post('/load/route-balanced', [LoadDistributionController::class, 'routeBalanced']);
    Route::post('/load/process', [LoadDistributionController::class, 'process']);
    Route::post('/load/process-single', [LoadDistributionController::class, 'processSingle']);
    Route::post('/load/process-balanced', [LoadDistributionController::class, 'processBalanced']);
    Route::get('/load/distribution-stats', [LoadDistributionController::class, 'stats']);
    Route::post('/load/set-server-health', [LoadDistributionController::class, 'setServerHealth']);
    Route::post('/load/distribution-reset', [LoadDistributionController::class, 'reset']);

    Route::get('/performance/stats', [PerformanceMonitoringController::class, 'stats']);
    Route::post('/performance/reset', [PerformanceMonitoringController::class, 'reset']);
    Route::post('/performance/demo-reset', [PerformanceMonitoringController::class, 'demoReset']);

    Route::get('/products/{product}/direct', [ProductCatalogController::class, 'showDirect']);
    Route::get('/products/{product}/cached', [ProductCatalogController::class, 'showCached']);
    Route::get('/cache/stats', [ProductCatalogController::class, 'cacheStats']);
    Route::post('/cache/reset', [ProductCatalogController::class, 'cacheReset']);
    Route::post('/cache/warm-popular', [ProductCatalogController::class, 'warmPopular']);
// });

Route::get('/test-queue', [OrderController::class, 'testQueue']);