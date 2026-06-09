<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ConcurrencyControl\ConcurrencyControlMetrics;
use App\Services\ConcurrencyControl\OptimisticStockPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_locking.lock_store' => 'array']);
        app(ConcurrencyControlMetrics::class)->reset();
    }

    public function test_optimistic_purchase_succeeds_and_increments_version(): void
    {
        $product = Product::query()->create([
            'name' => 'Optimistic Product',
            'stock' => 5,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/buy-optimistic', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('concurrency_strategy', 'optimistic')
            ->assertJsonPath('conflict', false)
            ->assertJsonPath('version', 1);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
            'version' => 1,
        ]);
    }

    public function test_optimistic_depletes_stock_without_overselling_sequentially(): void
    {
        $product = Product::query()->create([
            'name' => 'Optimistic Sequential',
            'stock' => 1,
            'version' => 0,
        ]);

        $this->postJson('/api/buy-optimistic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/buy-optimistic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 0,
        ]);
    }

    public function test_optimistic_version_conflict_returns_409(): void
    {
        Product::query()->create([
            'name' => 'Conflict API',
            'stock' => 5,
            'version' => 1,
        ]);

        $this->mock(OptimisticStockPurchaseService::class, function ($mock): void {
            $mock->shouldReceive('purchase')
                ->once()
                ->andReturn([
                    'status' => 'version_conflict',
                    'stock' => 4,
                    'order_id' => null,
                    'version' => 2,
                    'conflict' => true,
                ]);
        });

        $this->postJson('/api/buy-optimistic', [
            'product_id' => 1,
            'quantity' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('concurrency_strategy', 'optimistic')
            ->assertJsonPath('conflict', true);
    }

    public function test_distributed_lock_does_not_oversell_on_sequential_requests(): void
    {
        $product = Product::query()->create([
            'name' => 'Distributed Product',
            'stock' => 1,
            'version' => 0,
        ]);

        $this->postJson('/api/buy-distributed-lock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/buy-distributed-lock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 0,
        ]);
    }

    public function test_buy_distributed_lock_endpoint_returns_lock_acquired(): void
    {
        $product = Product::query()->create([
            'name' => 'API Distributed',
            'stock' => 4,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/buy-distributed-lock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('concurrency_strategy', 'distributed_pessimistic')
            ->assertJsonPath('lock_acquired', true)
            ->assertJsonPath('stock', 3);
    }

    public function test_concurrency_stats_reflect_metrics(): void
    {
        $product = Product::query()->create([
            'name' => 'Stats Product',
            'stock' => 2,
            'version' => 0,
        ]);

        $this->postJson('/api/buy-optimistic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $response = $this->getJson('/api/concurrency/stats');

        $response->assertOk()
            ->assertJsonPath('metrics.optimistic_successes', 1);
    }

    public function test_concurrency_reset_clears_metrics(): void
    {
        Product::query()->create([
            'name' => 'Reset Product',
            'stock' => 2,
            'version' => 0,
        ]);

        $this->postJson('/api/buy-optimistic', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/concurrency/reset')->assertOk();

        $this->getJson('/api/concurrency/stats')
            ->assertOk()
            ->assertJsonPath('metrics.optimistic_successes', 0);
    }
}
