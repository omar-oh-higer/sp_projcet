<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrentStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_purchase_with_lock_decrements_stock_and_creates_order(): void
    {
        $product = Product::query()->create([
            'name' => 'Test Product',
            'stock' => 10,
        ]);

        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Purchased WITH lock'])
            ->assertJsonFragment(['stock' => 7]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 7,
        ]);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'quantity' => 3,
            'status' => 'success',
        ]);
    }

    public function test_failed_purchase_does_not_change_stock_and_records_failed_order(): void
    {
        $product = Product::query()->create([
            'name' => 'Low Stock Product',
            'stock' => 2,
        ]);

        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(409)
            ->assertJsonFragment(['message' => 'Insufficient stock'])
            ->assertJsonFragment(['stock' => 2]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 2,
        ]);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'failed',
            'failure_reason' => 'insufficient_stock',
        ]);
    }

    public function test_repeated_requests_never_oversell_stock(): void
    {
        $product = Product::query()->create([
            'name' => 'Contention Product',
            'stock' => 5,
        ]);

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/buy-with-lock', [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

            if ($response->status() === 200) {
                $successCount++;
            }

            if ($response->status() === 409) {
                $failureCount++;
            }
        }

        $product->refresh();

        $this->assertSame(5, $successCount);
        $this->assertSame(15, $failureCount);
        $this->assertSame(0, $product->stock);

        $this->assertSame(5, Order::query()->where('status', 'success')->count());
        $this->assertSame(15, Order::query()->where('status', 'failed')->count());
    }
}
