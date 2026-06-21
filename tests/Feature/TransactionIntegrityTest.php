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

    public function test_integrity_stats_includes_enriched_demo_fields(): void
    {
        $product = Product::query()->create([
            'name' => 'Enriched Stats Product',
            'stock' => 10,
            'price_cents' => 500,
            'version' => 0,
        ]);

        $response = $this->getJson('/api/checkout/integrity-stats?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonStructure([
                'metrics',
                'recent_checkouts',
                'db_audit' => ['orphan_payments', 'orders_without_payment'],
                'scenario_summary',
                'product_snapshot',
                'demo_stock',
                'demo_request_delay_ms',
                'refreshed_at',
            ]);
    }

    public function test_demo_reset_restores_stock_and_clears_orphan_payments(): void
    {
        $demoStock = (int) config('checkout_integrity.demo_stock', 10);

        $product = Product::query()->create([
            'name' => 'Reset Product',
            'stock' => 3,
            'price_cents' => 800,
            'version' => 2,
        ]);

        Payment::query()->create([
            'product_id' => $product->id,
            'amount_cents' => 800,
            'status' => 'captured',
            'payment_reference' => 'pay_orphan_test',
            'order_id' => null,
        ]);

        $response = $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('stock', $demoStock)
            ->assertJsonPath('orphan_payments', 0)
            ->assertJsonPath('metrics_reset', true);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => $demoStock,
            'version' => 0,
        ]);

        $this->assertSame(0, Payment::query()->whereNull('order_id')->count());
    }

    public function test_demo_reset_with_reset_metrics_false_keeps_checkout_log(): void
    {
        $product = Product::query()->create([
            'name' => 'Keep Log Product',
            'stock' => 10,
            'price_cents' => 600,
            'version' => 0,
        ]);

        $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $beforeReset = $this->getJson('/api/checkout/integrity-stats?product_id='.$product->id);
        $logCountBefore = count($beforeReset->json('recent_checkouts'));

        $this->assertGreaterThan(0, $logCountBefore);

        $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
            'reset_metrics' => false,
        ])->assertOk();

        $afterReset = $this->getJson('/api/checkout/integrity-stats?product_id='.$product->id);

        $this->assertSame($logCountBefore, count($afterReset->json('recent_checkouts')));
        $this->assertSame(0, $afterReset->json('db_audit.orphan_payments'));
    }

    public function test_metrics_persist_across_separate_http_requests(): void
    {
        $product = Product::query()->create([
            'name' => 'Persist Product',
            'stock' => 5,
            'price_cents' => 400,
            'version' => 0,
        ]);

        $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $this->app->forgetInstance(CheckoutIntegrityMetrics::class);

        $response = $this->getJson('/api/checkout/integrity-stats');

        $response->assertOk()
            ->assertJsonPath('metrics.integrity_violations', 1)
            ->assertJsonPath('metrics.non_atomic_failures', 1);
    }

    public function test_full_scenario_sequence_non_atomic_orphan_then_acid_clean(): void
    {
        $demoStock = (int) config('checkout_integrity.demo_stock', 10);

        $product = Product::query()->create([
            'name' => 'Scenario Product',
            'stock' => $demoStock,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
        ])->assertOk();

        $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $this->assertSame(1, Payment::query()->whereNull('order_id')->count());

        $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
            'reset_metrics' => false,
        ])->assertOk()
            ->assertJsonPath('orphan_payments', 0);

        $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $this->assertSame(0, Payment::query()->whereNull('order_id')->count());

        $stats = $this->getJson('/api/checkout/integrity-stats?product_id='.$product->id);
        $stats->assertOk()
            ->assertJsonPath('db_audit.orphan_payments', 0)
            ->assertJsonPath('metrics.integrity_violations', 1);
    }

    public function test_acid_orphan_count_is_scoped_to_demo_product_not_global(): void
    {
        $demoStock = (int) config('checkout_integrity.demo_stock', 10);

        $product = Product::query()->create([
            'name' => 'Scoped Product',
            'stock' => $demoStock,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        $otherProduct = Product::query()->create([
            'name' => 'Other Product',
            'stock' => $demoStock,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        Payment::query()->create([
            'product_id' => $otherProduct->id,
            'amount_cents' => 1000,
            'status' => 'captured',
            'payment_reference' => 'pay_other_product_orphan',
            'order_id' => null,
        ]);

        $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
        ])->assertOk();

        $this->postJson('/api/checkout/non-atomic', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $this->postJson('/api/checkout/demo-reset', [
            'product_id' => $product->id,
            'reset_metrics' => false,
        ])->assertOk()
            ->assertJsonPath('orphan_payments', 0);

        $this->postJson('/api/checkout/acid', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'X-SIMULATE-FAIL-AT' => 'after_payment',
        ])->assertStatus(500);

        $stats = $this->getJson('/api/checkout/integrity-stats?product_id='.$product->id);
        $stats->assertOk()
            ->assertJsonPath('db_audit.orphan_payments', 0)
            ->assertJsonPath('metrics.orphan_payments', 0);

        $acidRow = collect($stats->json('recent_checkouts'))
            ->first(fn (array $row) => ($row['transaction_mode'] ?? '') === 'acid');

        $this->assertNotNull($acidRow);
        $this->assertSame(0, $acidRow['orphan_payments_after']);

        $this->assertSame(1, Payment::query()->whereNull('order_id')->count());
    }
}
