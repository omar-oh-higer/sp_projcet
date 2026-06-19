<?php

use App\Http\Controllers\LoadDistributionController;
use App\Http\Controllers\NfrDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/demo', [NfrDemoController::class, 'index'])->name('demo');

Route::post('/process', [LoadDistributionController::class, 'process']);
