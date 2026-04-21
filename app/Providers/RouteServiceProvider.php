<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider; // Added import for ServiceProvider

class RouteServiceProvider extends ServiceProvider // Added class declaration
{
    public function boot()
    {
        RateLimiter::for('orders', function ($request) {
            return Limit::perMinute(20)->by($request->ip());
        });
    }
}