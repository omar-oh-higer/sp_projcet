<?php

use App\Http\Controllers\DailySalesTallyController;
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
// });

Route::get('/test-queue', [OrderController::class, 'testQueue']);