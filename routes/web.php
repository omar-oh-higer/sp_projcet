<?php

use App\Http\Controllers\LoadDistributionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/process', [LoadDistributionController::class, 'process']);
