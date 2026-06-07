<?php

namespace App\Services\ProductCatalog;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Task 6 “after”: Cache-Aside (lazy loading) via Redis distributed cache. */
class CachedProductLookup
{
    public function __construct(
        private ProductCatalogMetrics $metrics,
        private ProductCacheInvalidator $invalidator,
    ) {}

    /**
     * @return array{found: bool, product: array<string, mixed>|null, lookup_mode: string, db_queries: int, cache_result: string}
     */
    public function find(int $productId): array
    {
        $storeName = (string) config('product_cache.store', 'redis');
        $key = $this->invalidator->cacheKey($productId);

        try {
            $store = Cache::store($storeName);
            $cached = $store->get($key);

            if ($cached !== null && is_array($cached)) {
                $this->metrics->cacheHits++;

                return [
                    'found' => true,
                    'product' => $cached,
                    'lookup_mode' => 'cache_aside',
                    'db_queries' => 0,
                    'cache_result' => 'hit',
                ];
            }

            $this->metrics->cacheMisses++;
            $this->metrics->dbQueries++;

            $product = Product::query()->find($productId);

            if (! $product) {
                return [
                    'found' => false,
                    'product' => null,
                    'lookup_mode' => 'cache_aside',
                    'db_queries' => 1,
                    'cache_result' => 'miss',
                ];
            }

            $payload = [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock,
                'cached_at' => now()->toIso8601String(),
            ];

            $store->put($key, $payload, config('product_cache.ttl_seconds', 300));

            return [
                'found' => true,
                'product' => $payload,
                'lookup_mode' => 'cache_aside',
                'db_queries' => 1,
                'cache_result' => 'miss',
            ];
        } catch (Throwable) {
            $this->metrics->cacheBypasses++;
            $this->metrics->dbQueries++;

            $product = Product::query()->find($productId);

            if (! $product) {
                return [
                    'found' => false,
                    'product' => null,
                    'lookup_mode' => 'cache_aside',
                    'db_queries' => 1,
                    'cache_result' => 'bypass',
                ];
            }

            return [
                'found' => true,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                ],
                'lookup_mode' => 'cache_aside',
                'db_queries' => 1,
                'cache_result' => 'bypass',
            ];
        }
    }

    /**
     * @return array<int, array{product_id: int, warmed: bool, cache_result: string}>
     */
    public function warmPopular(): array
    {
        $results = [];

        foreach (config('product_cache.popular_product_ids', []) as $productId) {
            $lookup = $this->find((int) $productId);
            $results[] = [
                'product_id' => (int) $productId,
                'warmed' => $lookup['found'],
                'cache_result' => $lookup['cache_result'],
            ];
        }

        return $results;
    }
}
