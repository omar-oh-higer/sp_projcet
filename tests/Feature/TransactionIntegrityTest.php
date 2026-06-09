<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\TransactionIntegrity\CheckoutIntegrityMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(CheckoutIntegrityMetrics::class)->reset();
    }

    public function test_acid_checkout_success_creates_payment_order_and_decrements_stock(): void
    {
        Queue::fake();

        $product = Product::query()->create([
            'name' => 'ACID Product',
            'stock' => 5,
            'price_cents' => 1500,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('transaction_mode', 'acid')
            ->assertJsonPath('amount_cents', 3000)
            ->assertJsonPath('integrity_violation', false);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);

        $this->assertDatabaseHas('payments', [
            'product_id' => $product->id,
            'amount_cents' => 3000,
            'status' => 'captured',
        ]);

        $orderId = $response->json('order_id');
        $paymentId = $response->json('payment_id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_id' => $paymentId,
            'status' => 'success',
        ]);
    }

    public function test_non_atomic_simulated_failure_after_payment_leaves_orphan_payment(): void
    {
        $product = Product::query()->create([
            'name' => 'Non Atomic Product',
            'stock' => 5,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('transaction_mode', 'non_atomic')
            ->assertJsonPath('integrity_violation', true)
            ->assertJsonPath('fail_at', 'after_payment');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'product_id' => $product->id,
            'status' => 'captured',
            'order_id' => null,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_acid_simulated_failure_after_payment_rolls_back_everything(): void
    {
        $product = Product::query()->create([
            'name' => 'ACID Rollback Product',
            'stock' => 5,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('transaction_mode', 'acid')
            ->assertJsonPath('rolled_back', true)
            ->assertJsonPath('integrity_violation', false);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);
    }

    public function test_non_atomic_simulated_failure_after_stock_leaves_inconsistent_state(): void
    {
        $product = Product::query()->create([
            'name' => 'Non Atomic Stock Fail',
            'stock' => 5,
            'price_cents' => 1200,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_stock',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('integrity_violation', true)
            ->assertJsonPath('fail_at', 'after_stock');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_acid_payment_declined_has_no_side_effects(): void
    {
        $product = Product::query()->create([
            'name' => 'Declined Product',
            'stock' => 4,
            'price_cents' => 500,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-PAYMENT-DECLINED' => '1',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'payment_declined')
            ->assertJsonPath('rolled_back', true);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);
    }

    public function test_acid_sequential_checkouts_do_not_oversell(): void
    {
        Queue::fake();

        $product = Product::query()->create([
            'name' => 'Last Unit Product',
            'stock' => 1,
            'price_cents' => 900,
            'version' => 0,
        ]);

        $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 0,
        ]);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Order::query()->where('status', 'success')->count());
    }

    public function test_integrity_stats_reflects_violations(): void
    {
        $product = Product::query()->create([
            'name' => 'Stats Product',
            'stock' => 3,
            'price_cents' => 700,
            'version' => 0,
        ]);

        $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $response = $this->getJson('/api/checkout/integrity-stats');

        $response->assertOk()
            ->assertJsonPath('metrics.integrity_violations', 1)
            ->assertJsonPath('metrics.orphan_payments', 1);
    }
}
