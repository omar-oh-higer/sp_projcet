<?php

namespace App\Services\ProductCatalog;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Builds lecture-style cache demo view for /demo. */
class ProductCatalogStatusBuilder
{
    public function build(ProductCatalogMetrics $metrics, ?int $exampleProductId = null): array
    {
        $storeName = (string) config('product_cache.store', 'redis');
        $productId = $exampleProductId ?? (int) (config('product_cache.popular_product_ids')[0] ?? 1);
        $invalidator = app(ProductCacheInvalidator::class);
        $cacheKey = $invalidator->cacheKey($productId);

        $recent = $metrics->recentLookups();
        $redisReachable = $this->probeRedisStore($storeName);

        $directDbQueries = 0;
        $cachedDbQueries = 0;

        foreach ($recent as $row) {
            if ($row['endpoint'] === 'direct') {
                $directDbQueries += (int) $row['db_queries'];
            } elseif ($row['endpoint'] === 'cached') {
                $cachedDbQueries += (int) $row['db_queries'];
            }
        }

        $redisHasKey = false;

        if ($redisReachable) {
            try {
                $redisHasKey = Cache::store($storeName)->has($cacheKey);
            } catch (Throwable) {
                $redisHasKey = false;
            }
        }

        return [
            'recent_lookups' => array_map(fn (array $row) => [
                ...$row,
                'message_en' => $this->messageEn($row),
                'message_ar' => $this->messageAr($row),
            ], $recent),
            'cache_key_example' => $cacheKey,
            'example_product_id' => $productId,
            'redis_store' => $storeName,
            'redis_reachable' => $redisReachable,
            'redis_key_populated' => $redisHasKey,
            'ttl_seconds' => (int) config('product_cache.ttl_seconds', 300),
            'popular_product_ids' => config('product_cache.popular_product_ids', []),
            'scenario_summary' => [
                'direct_db_queries' => $directDbQueries,
                'cached_db_queries' => $cachedDbQueries,
                'lookup_count' => count($recent),
            ],
            'redis_hint_en' => $redisReachable
                ? null
                : 'Redis unreachable — set PRODUCT_CACHE_STORE=redis and start Redis. Cached lookups will bypass to DB.',
            'redis_hint_ar' => $redisReachable
                ? null
                : 'Redis غير متاح — شغّل Redis و PRODUCT_CACHE_STORE=redis',
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function probeRedisStore(string $storeName): bool
    {
        if ($storeName === 'array') {
            return true;
        }

        try {
            $store = Cache::store($storeName);
            $probeKey = 'product_catalog:demo_probe:'.uniqid('', true);
            $store->put($probeKey, 'ok', 5);
            $ok = $store->get($probeKey) === 'ok';
            $store->forget($probeKey);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    private function messageEn(array $row): string
    {
        $endpoint = (string) ($row['endpoint'] ?? '');
        $result = $row['cache_result'] ?? null;
        $db = (int) ($row['db_queries'] ?? 0);

        if ($endpoint === 'direct') {
            return "Direct DB lookup — always 1 query (no Redis). Total DB: {$db}.";
        }

        return match ($result) {
            'hit' => 'Cache HIT — served from Redis, 0 DB queries.',
            'miss' => 'Cache MISS — read DB, stored in Redis for next request.',
            'bypass' => 'Redis unavailable — fell back to DB (bypass).',
            default => "Cached lookup ({$result}). DB queries: {$db}.",
        };
    }

    /** @param array<string, mixed> $row */
    private function messageAr(array $row): string
    {
        $endpoint = (string) ($row['endpoint'] ?? '');
        $result = $row['cache_result'] ?? null;

        if ($endpoint === 'direct') {
            return 'استعلام DB مباشر — دائماً 1 query بدون Redis.';
        }

        return match ($result) {
            'hit' => 'إصابة كاش — من Redis، 0 استعلام DB.',
            'miss' => 'فوت كاش — قراءة DB ثم تخزين في Redis.',
            'bypass' => 'Redis غير متاح — رجوع إلى DB.',
            default => "lookup كاش ({$result}).",
        };
    }
}
