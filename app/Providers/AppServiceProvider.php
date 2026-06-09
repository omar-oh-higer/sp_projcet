<?php

namespace App\Providers;

use App\Services\ConcurrencyControl\ConcurrencyControlMetrics;
use App\Services\ProductCatalog\ProductCatalogMetrics;
use App\Services\StressTesting\StressTestMetrics;
use App\Services\TransactionIntegrity\CheckoutIntegrityMetrics;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProductCatalogMetrics::class);
        $this->app->singleton(ConcurrencyControlMetrics::class);
        $this->app->singleton(CheckoutIntegrityMetrics::class);
        $this->app->singleton(StressTestMetrics::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
