<?php

namespace App\Services\ProductCatalog;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Task 6: manual cache invalidation when product data changes (e.g. stock after purchase). */
class ProductCacheInvalidator
{
    public function cacheKey(int $productId): string
    {
        return config('product_cache.key_prefix', 'product_catalog:').'product:'.$productId;
    }

    public function forget(int $productId): void
    {
        try {
            Cache::store(config('product_cache.store', 'redis'))->forget($this->cacheKey($productId));
        } catch (Throwable) {
            // Fail open: cache down must not break purchases.
        }
    }

    public function forgetAllPopular(): void
    {
        foreach (config('product_cache.popular_product_ids', []) as $productId) {
            $this->forget((int) $productId);
        }
    }
}
