<?php

namespace App\Services\ProductCatalog;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/** Demo counters + lookup log persisted across HTTP requests (Task 6 stats API). */
class ProductCatalogMetrics
{
    private const MAX_RECENT = 30;

    public function incrementDbQueries(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['db_queries']++;
        });
    }

    public function incrementCacheHits(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['cache_hits']++;
        });
    }

    public function incrementCacheMisses(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['cache_misses']++;
        });
    }

    public function incrementCacheBypasses(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['cache_bypasses']++;
        });
    }

    public function reset(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /**
     * @param  'direct'|'cached'  $endpoint
     * @param  'hit'|'miss'|'bypass'|null  $cacheResult
     */
    public function recordLookup(
        int $productId,
        string $endpoint,
        ?string $cacheResult,
        int $dbQueries,
        string $lookupMode,
    ): void {
        $this->mutate(static function (array &$state) use ($productId, $endpoint, $cacheResult, $dbQueries, $lookupMode): void {
            $state['lookup_sequence']++;

            $state['recent_lookups'][] = [
                'lookup_index' => $state['lookup_sequence'],
                'product_id' => $productId,
                'endpoint' => $endpoint,
                'lookup_mode' => $lookupMode,
                'cache_result' => $cacheResult,
                'db_queries' => $dbQueries,
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($state['recent_lookups']) > self::MAX_RECENT) {
                array_shift($state['recent_lookups']);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function recentLookups(): array
    {
        return $this->loadState()['recent_lookups'];
    }

    /** @return array<string, mixed> */
    public function snapshot(string $storeName): array
    {
        $state = $this->loadState();
        $lookups = $state['cache_hits'] + $state['cache_misses'] + $state['cache_bypasses'];
        $hitRate = $lookups > 0
            ? round(($state['cache_hits'] / $lookups) * 100, 2)
            : 0.0;

        return [
            'cache_store' => $storeName,
            'db_queries_total' => $state['db_queries'],
            'cache_hits' => $state['cache_hits'],
            'cache_misses' => $state['cache_misses'],
            'cache_bypasses' => $state['cache_bypasses'],
            'cache_lookups' => $lookups,
            'hit_rate_percent' => $hitRate,
        ];
    }

    /** @param  callable(array<string, mixed>&): void  $callback */
    private function mutate(callable $callback): void
    {
        $state = $this->loadState();
        $callback($state);
        $this->saveState($state);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        /** @var array<string, mixed>|null $state */
        $state = $this->store()->get($this->cacheKey());

        if (! is_array($state)) {
            return $this->emptyState();
        }

        return array_merge($this->emptyState(), $state);
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): void
    {
        $this->store()->forever($this->cacheKey(), $state);
    }

    /** @return array<string, mixed> */
    private function emptyState(): array
    {
        return [
            'db_queries' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'cache_bypasses' => 0,
            'recent_lookups' => [],
            'lookup_sequence' => 0,
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('product_cache.metrics_cache_key', 'product_catalog:demo_metrics');
    }

    private function store(): CacheRepository
    {
        $storeName = config('product_cache.metrics_store');

        return $storeName
            ? Cache::store((string) $storeName)
            : Cache::store();
    }
}
