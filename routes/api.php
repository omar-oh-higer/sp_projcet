<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/buy-without-lock', [OrderController::class, 'buyWithoutLock'] );
Route::post('/buy-with-lock', [OrderController::class, 'buyWithLock'] );
Route::middleware('throttle:orders')->post('/buy-with-lock', [OrderController::class, 'buyWithLock']);
Route::get('/test-queue', [OrderController::class, 'testQueue']);