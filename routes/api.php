<?php

use App\Http\Controllers\DailySalesTallyController;
use App\Http\Controllers\LoadDistributionController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Route::middleware('throttle:purchases')->group(function () {
    Route::post('/buy-without-lock', [OrderController::class, 'buyWithoutLock']);

    Route::middleware('circuit.breaker')->group(function () {
        Route::post('/buy-with-lock', [OrderController::class, 'buyWithLock']);
        Route::post('/buy-with-lock-wait-invoice', [OrderController::class, 'buyWithLockWaitForInvoice']);
    });

    Route::post('/tally-daily-sales-wait', [DailySalesTallyController::class, 'tallyWait']);
    Route::post('/tally-daily-sales-queued', [DailySalesTallyController::class, 'tallyQueued']);
    Route::get('/daily-sales-summary', [DailySalesTallyController::class, 'showSummary']);

    Route::post('/load/route-single', [LoadDistributionController::class, 'routeSingle']);
    Route::post('/load/route-balanced', [LoadDistributionController::class, 'routeBalanced']);
    Route::get('/load/distribution-stats', [LoadDistributionController::class, 'stats']);
    Route::post('/load/set-server-health', [LoadDistributionController::class, 'setServerHealth']);
    Route::post('/load/distribution-reset', [LoadDistributionController::class, 'reset']);
// });

Route::get('/test-queue', [OrderController::class, 'testQueue']);