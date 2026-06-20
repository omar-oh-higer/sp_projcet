<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductCatalog\ProductCatalogMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DistributedCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['product_cache.store' => 'array']);
        app(ProductCatalogMetrics::class)->reset();
        Cache::store('array')->flush();
    }

    public function test_direct_lookup_always_queries_database(): void
    {
        Product::query()->create([
            'name' => 'Cached Demo Product',
            'stock' => 10,
        ]);

        $response = $this->getJson('/api/products/1/direct');

        $response->assertOk()
            ->assertJsonPath('lookup_mode', 'direct_db')
            ->assertJsonPath('db_queries', 1)
            ->assertJsonPath('product.name', 'Cached Demo Product');

        $second = $this->getJson('/api/products/1/direct');
        $second->assertOk()->assertJsonPath('db_queries', 1);
    }

    public function test_cached_lookup_miss_then_hit(): void
    {
        Product::query()->create([
            'name' => 'Redis Product',
            'stock' => 5,
        ]);

        $miss = $this->getJson('/api/products/1/cached');
        $miss->assertOk()
            ->assertJsonPath('lookup_mode', 'cache_aside')
            ->assertJsonPath('cache_result', 'miss')
            ->assertJsonPath('db_queries', 1);

        $hit = $this->getJson('/api/products/1/cached');
        $hit->assertOk()
            ->assertJsonPath('cache_result', 'hit')
            ->assertJsonPath('db_queries', 0);
    }

    public function test_successful_purchase_invalidates_product_cache(): void
    {
        Product::query()->create([
            'name' => 'Invalidation Product',
            'stock' => 10,
        ]);

        $this->getJson('/api/products/1/cached')->assertJsonPath('cache_result', 'miss');
        $this->getJson('/api/products/1/cached')->assertJsonPath('cache_result', 'hit');

        $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->getJson('/api/products/1/cached')
            ->assertOk()
            ->assertJsonPath('cache_result', 'miss')
            ->assertJsonPath('product.stock', 9);
    }

    public function test_cache_stats_reflect_hit_rate(): void
    {
        Product::query()->create([
            'name' => 'Stats Product',
            'stock' => 3,
        ]);

        $this->getJson('/api/products/1/cached')->assertOk();
        $this->getJson('/api/products/1/cached')->assertOk();

        $response = $this->getJson('/api/cache/stats');

        $response->assertOk()
            ->assertJsonPath('pattern', 'cache_aside')
            ->assertJsonPath('metrics.cache_hits', 1)
            ->assertJsonPath('metrics.cache_misses', 1)
            ->assertJsonStructure([
                'recent_lookups',
                'cache_key_example',
                'scenario_summary',
                'redis_reachable',
            ])
            ->assertJsonPath('cache_key_example', 'product_catalog:product:1');
    }

    public function test_cache_stats_recent_lookups_log_direct_and_cached_sequence(): void
    {
        Product::query()->create([
            'name' => 'Log Product',
            'stock' => 10,
        ]);

        $this->getJson('/api/products/1/direct')->assertOk();
        $this->getJson('/api/products/1/direct')->assertOk();
        $this->getJson('/api/products/1/cached')->assertOk();
        $this->getJson('/api/products/1/cached')->assertOk();

        $response = $this->getJson('/api/cache/stats?product_id=1');

        $response->assertOk()
            ->assertJsonCount(4, 'recent_lookups')
            ->assertJsonPath('recent_lookups.0.endpoint', 'direct')
            ->assertJsonPath('recent_lookups.1.endpoint', 'direct')
            ->assertJsonPath('recent_lookups.2.endpoint', 'cached')
            ->assertJsonPath('recent_lookups.2.cache_result', 'miss')
            ->assertJsonPath('recent_lookups.3.endpoint', 'cached')
            ->assertJsonPath('recent_lookups.3.cache_result', 'hit')
            ->assertJsonPath('scenario_summary.direct_db_queries', 2)
            ->assertJsonPath('scenario_summary.cached_db_queries', 1);
    }

    public function test_invalidation_appears_in_recent_lookups_as_miss(): void
    {
        Product::query()->create([
            'name' => 'Invalidation Log Product',
            'stock' => 10,
        ]);

        $this->getJson('/api/products/1/cached')->assertJsonPath('cache_result', 'miss');
        $this->getJson('/api/products/1/cached')->assertJsonPath('cache_result', 'hit');

        $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->getJson('/api/products/1/cached')
            ->assertOk()
            ->assertJsonPath('cache_result', 'miss');

        $response = $this->getJson('/api/cache/stats?product_id=1');

        $response->assertOk()
            ->assertJsonCount(3, 'recent_lookups')
            ->assertJsonPath('recent_lookups.0.cache_result', 'miss')
            ->assertJsonPath('recent_lookups.1.cache_result', 'hit')
            ->assertJsonPath('recent_lookups.2.cache_result', 'miss');
    }

    public function test_warm_popular_loads_configured_products(): void
    {
        Product::query()->insert([
            ['name' => 'Popular A', 'stock' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Popular B', 'stock' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Popular C', 'stock' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['product_cache.popular_product_ids' => [1, 2, 3]]);

        $response = $this->postJson('/api/cache/warm-popular');

        $response->assertOk()
            ->assertJsonCount(3, 'warmed')
            ->assertJsonPath('warmed.0.warmed', true);
    }

    public function test_cache_reset_clears_metrics(): void
    {
        Product::query()->create([
            'name' => 'Reset Product',
            'stock' => 2,
        ]);

        $this->getJson('/api/products/1/cached')->assertOk();

        $this->postJson('/api/cache/reset')->assertOk();

        $this->getJson('/api/cache/stats')
            ->assertOk()
            ->assertJsonPath('metrics.cache_hits', 0)
            ->assertJsonPath('metrics.cache_misses', 0);
    }
}
