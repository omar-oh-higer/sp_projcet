<?php

namespace Tests\Feature;

use App\Jobs\ReleaseInvoiceJob;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsynchronousQueuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->create([
            'id' => 1,
            'name' => 'Keyboard',
            'stock' => 100,
        ]);
    }

    public function test_buy_with_lock_dispatches_release_invoice_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['invoice_mode' => 'queued'])
            ->assertJsonFragment(['message' => 'Purchased WITH lock']);

        Queue::assertPushed(ReleaseInvoiceJob::class, 1);

        $this->assertDatabaseHas('products', [
            'id' => 1,
            'stock' => 98,
        ]);
    }

    public function test_buy_with_lock_wait_invoice_runs_inline_without_queue_dispatch(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/buy-with-lock-wait-invoice', [
            'product_id' => 1,
            'quantity' => 1,
        ], [
            'X-INVOICE-DELAY-MS' => '0',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['invoice_mode' => 'inline'])
            ->assertJsonFragment(['message' => 'Purchased WITH lock (invoice inline)']);

        Queue::assertNotPushed(ReleaseInvoiceJob::class);

        $orderId = $response->json('order_id');
        $this->assertNotNull($orderId);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'product_id' => 1,
            'quantity' => 1,
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => 1,
            'stock' => 99,
        ]);
    }
}
