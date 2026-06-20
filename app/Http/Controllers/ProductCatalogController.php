<?php

namespace App\Http\Controllers;

use App\Services\ProductCatalog\CachedProductLookup;
use App\Services\ProductCatalog\DirectProductLookup;
use App\Services\ProductCatalog\ProductCacheInvalidator;
use App\Services\ProductCatalog\ProductCatalogMetrics;
use App\Services\ProductCatalog\ProductCatalogStatusBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Task 6: direct DB product lookup vs Redis Cache-Aside demo. */
class ProductCatalogController extends Controller
{
    public function showDirect(int $product, DirectProductLookup $lookup): JsonResponse
    {
        $result = $lookup->find($product);

        if (! $result['found']) {
            return response()->json([
                'message' => 'Product not found',
                'lookup_mode' => $result['lookup_mode'],
                'db_queries' => $result['db_queries'],
            ], 404);
        }

        return response()->json([
            'message' => 'Product loaded directly from database (no cache).',
            'lookup_mode' => $result['lookup_mode'],
            'db_queries' => $result['db_queries'],
            'cache_result' => $result['cache_result'],
            'product' => $result['product'],
        ]);
    }

    public function showCached(int $product, CachedProductLookup $lookup): JsonResponse
    {
        $result = $lookup->find($product);

        if (! $result['found']) {
            return response()->json([
                'message' => 'Product not found',
                'lookup_mode' => $result['lookup_mode'],
                'db_queries' => $result['db_queries'],
                'cache_result' => $result['cache_result'],
            ], 404);
        }

        return response()->json([
            'message' => $result['cache_result'] === 'hit'
                ? 'Product served from Redis cache (Cache-Aside hit).'
                : 'Product loaded from database and stored in Redis (Cache-Aside miss).',
            'lookup_mode' => $result['lookup_mode'],
            'db_queries' => $result['db_queries'],
            'cache_result' => $result['cache_result'],
            'product' => $result['product'],
        ]);
    }

    public function cacheStats(
        ProductCatalogMetrics $metrics,
        ProductCatalogStatusBuilder $statusBuilder,
        Request $request,
    ): JsonResponse {
        $storeName = (string) config('product_cache.store', 'redis');
        $exampleProductId = $request->integer('product_id') ?: null;
        $enriched = $statusBuilder->build($metrics, $exampleProductId);

        return response()->json(array_merge([
            'message' => 'Product catalog cache statistics (Session 6 demo).',
            'pattern' => 'cache_aside',
            'metrics' => $metrics->snapshot($storeName),
            'popular_product_ids' => config('product_cache.popular_product_ids', []),
            'ttl_seconds' => config('product_cache.ttl_seconds', 300),
            'demo_request_delay_ms' => (int) config('product_cache.demo_request_delay_ms', 400),
        ], $enriched));
    }

    public function cacheReset(
        ProductCatalogMetrics $metrics,
        ProductCacheInvalidator $invalidator,
    ): JsonResponse {
        $invalidator->forgetAllPopular();
        $metrics->reset();

        return response()->json([
            'message' => 'Product cache keys and demo counters reset.',
        ]);
    }

    public function warmPopular(CachedProductLookup $lookup): JsonResponse
    {
        $warmed = $lookup->warmPopular();

        return response()->json([
            'message' => 'Popular products warmed into Redis via Cache-Aside.',
            'warmed' => $warmed,
        ]);
    }
}
