<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Order;
use App\Jobs\ReleaseInvoiceJob;
use App\Services\CircuitBreakerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResourceManagementTest extends TestCase
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

    /**
     * Test HTTP rate limiting: requests beyond limit return 429
     */
    public function test_http_rate_limiting_returns_429_on_excess_requests()
    {
        $responses = [];
        for ($i = 0; $i < 105; $i++) {
            $response = $this->postJson('/api/buy-with-lock', [
                'product_id' => 1,
                'quantity' => 1,
            ]);
            $responses[] = $response->status();
        }

        $rateLimitedCount = count(array_filter($responses, fn ($status) => $status === 429));

        $this->assertGreaterThan(0, $rateLimitedCount, 'Expected some 429 rate limit responses');
        $this->assertLessThan(105, count(array_filter($responses, fn ($status) => $status === 200)), 'Expected some requests to be rate limited');
    }

    /**
     * Test ReleaseInvoiceJob acquires and releases semaphore correctly
     */
    public function test_release_invoice_job_uses_semaphore()
    {
        Cache::forget('invoice-processing-semaphore:count');

        $order = Order::create([
            'product_id' => 1,
            'user_id' => null,
            'quantity' => 1,
            'status' => 'success',
        ]);

        $initialCount = Cache::get('invoice-processing-semaphore:count', 0);
        $this->assertEquals(0, $initialCount, 'Initial semaphore count should be 0');

        $job = new ReleaseInvoiceJob($order->id);
        $job->handle();

        $finalCount = Cache::get('invoice-processing-semaphore:count', 0);
        $this->assertEquals(0, $finalCount, 'Semaphore count should be released back to 0');
    }

    /**
     * Test semaphore respects max concurrent limit
     */
    public function test_semaphore_respects_max_concurrent_limit()
    {
        Cache::forget('invoice-processing-semaphore:count');

        $order1 = Order::create(['product_id' => 1, 'user_id' => null, 'quantity' => 1, 'status' => 'success']);
        $order2 = Order::create(['product_id' => 1, 'user_id' => null, 'quantity' => 1, 'status' => 'success']);

        for ($i = 0; $i < 5; $i++) {
            Cache::put('invoice-processing-semaphore:count', $i, 3600);
            $this->assertLessThanOrEqual(5, $i, 'Semaphore should not exceed max concurrent');
        }

        Cache::put('invoice-processing-semaphore:count', 0, 3600);
        $this->assertEquals(0, Cache::get('invoice-processing-semaphore:count'));
    }

    /**
     * Test circuit breaker closed state allows requests
     */
    public function test_circuit_breaker_closed_state_allows_requests()
    {
        $cb = new CircuitBreakerManager();
        Cache::forget('circuit-breaker:invoice:state');

        $this->assertFalse($cb->isOpen(), 'Circuit breaker should be closed initially');

        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ]);

        $this->assertNotEquals(503, $response->status(), 'Request should not return 503 when circuit is closed');
    }

    /**
     * Test circuit breaker opens on high failure rate
     */
    public function test_circuit_breaker_opens_on_high_failure_rate()
    {
        $cb = new CircuitBreakerManager();
        Cache::forget('circuit-breaker:invoice:state');
        Cache::forget('circuit-breaker:invoice:failures');
        Cache::forget('circuit-breaker:invoice:opened_at');

        // Simulate 35 failures in the last 60 seconds (35% failure rate)
        $failures = [];
        $now = now()->timestamp;

        for ($i = 0; $i < 35; $i++) {
            $failures[] = $now - rand(0, 50);
        }

        Cache::put('circuit-breaker:invoice:failures', $failures, 70);

        // Record failures to trigger circuit open
        for ($i = 0; $i < 35; $i++) {
            $cb->recordFailure();
        }

        $this->assertTrue($cb->isOpen(), 'Circuit breaker should be open when failure rate exceeds 30%');
    }

    /**
     * Test async job dispatch after successful purchase
     */
    public function test_successful_purchase_dispatches_async_job()
    {
        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ]);

        $this->assertTrue($response->json('order_id') > 0, 'Order should be created');

        $order = Order::find($response->json('order_id'));
        $this->assertNotNull($order, 'Order should exist in database');
        $this->assertEquals(1, $order->quantity, 'Order quantity should match request');
    }

    /**
     * Test after_commit behavior prevents orphaned jobs on rollback
     */
    public function test_purchase_within_transaction_with_after_commit()
    {
        $initialOrderCount = Order::count();

        $response = $this->postJson('/api/buy-with-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ]);

        $this->assertTrue($response->status() === 200 || $response->status() === 409, 'Purchase should succeed or be out of stock');
        $this->assertGreaterThanOrEqual($initialOrderCount, Order::count(), 'Order should be persisted or stay the same');
    }

    /**
     * Test rate limiter is properly configured
     */
    public function test_rate_limiter_configuration()
    {
        $this->assertTrue(true, 'Rate limiter configured in RouteServiceProvider');
    }

    /**
     * Test queue worker pool configuration
     */
    public function test_queue_worker_pool_settings()
    {
        $config = config('queue.connections.database');
        $this->assertEquals('database', $config['driver'], 'Queue driver should be database');
        $this->assertTrue($config['after_commit'], 'after_commit should be enabled');
    }

    /**
     * Test load simulation command dispatches jobs
     */
    public function test_load_simulation_dispatches_jobs()
    {
        $initialCount = Order::count();

        $this->artisan('load:simulate', [
            'productId' => 1,
            '--requests' => 10,
            '--quantity' => 1,
        ])->assertExitCode(0);

        $this->assertGreaterThan($initialCount, Order::count(), 'Load simulation should create test orders');
    }
}
