<?php

namespace App\Services\ProductCatalog;

/** In-memory counters for Task 6 cache demo stats (reset via API). */
class ProductCatalogMetrics
{
    public int $dbQueries = 0;

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    public int $cacheBypasses = 0;

    public function reset(): void
    {
        $this->dbQueries = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheBypasses = 0;
    }

    /** @return array<string, mixed> */
    public function snapshot(string $storeName): array
    {
        $lookups = $this->cacheHits + $this->cacheMisses + $this->cacheBypasses;
        $hitRate = $lookups > 0
            ? round(($this->cacheHits / $lookups) * 100, 2)
            : 0.0;

        return [
            'cache_store' => $storeName,
            'db_queries_total' => $this->dbQueries,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_bypasses' => $this->cacheBypasses,
            'cache_lookups' => $lookups,
            'hit_rate_percent' => $hitRate,
        ];
    }
}
