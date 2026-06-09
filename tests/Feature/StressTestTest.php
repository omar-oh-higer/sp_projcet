<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\StressTesting\ConcurrentStressRunner;
use App\Services\StressTesting\StressTestIntegrityChecker;
use App\Services\StressTesting\StressTestMetrics;
use App\Services\StressTesting\StressTestReportBuilder;
use App\Services\StressTesting\StressTestScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StressTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(StressTestMetrics::class)->reset();
    }

    public function test_integrity_checker_passes_when_stock_matches_successes(): void
    {
        $checker = app(StressTestIntegrityChecker::class);

        $before = [
            'product_id' => 1,
            'stock' => 10,
            'successful_orders' => 0,
            'captured_payments' => 0,
            'orphan_payments' => 0,
        ];

        $after = [
            'product_id' => 1,
            'stock' => 3,
            'successful_orders' => 7,
            'captured_payments' => 7,
            'orphan_payments' => 0,
        ];

        $result = $checker->evaluate(
            before: $before,
            after: $after,
            successRequests: 7,
            quantity: 1,
            scenario: StressTestScenario::safeAcid(),
        );

        $this->assertTrue($result['data_integrity_pass']);
        $this->assertSame(7, $result['units_sold_expected']);
        $this->assertSame(7, $result['units_sold_actual']);
        $this->assertSame(7, $result['orders_created']);
    }

    public function test_integrity_checker_fails_on_oversell(): void
    {
        $checker = app(StressTestIntegrityChecker::class);

        $before = [
            'product_id' => 1,
            'stock' => 5,
            'successful_orders' => 0,
            'captured_payments' => 0,
            'orphan_payments' => 0,
        ];

        $after = [
            'product_id' => 1,
            'stock' => -2,
            'successful_orders' => 8,
            'captured_payments' => 8,
            'orphan_payments' => 0,
        ];

        $result = $checker->evaluate(
            before: $before,
            after: $after,
            successRequests: 8,
            quantity: 1,
            scenario: StressTestScenario::safeAcid(),
        );

        $this->assertFalse($result['data_integrity_pass']);
    }

    public function test_report_builder_includes_required_metrics(): void
    {
        $builder = app(StressTestReportBuilder::class);

        $runMetrics = [
            'total_requests' => 100,
            'success_requests' => 20,
            'failed_requests' => 2,
            'rejected_requests' => 78,
            'connection_errors' => 0,
            'average_response_time_ms' => 45.5,
            'average_server_response_time_ms' => 45.5,
            'pool_duration_ms' => 1200.0,
            'system_crashed' => false,
            'request_results' => [],
        ];

        $integrity = [
            'stock_before' => 40,
            'stock_after' => 20,
            'units_sold_expected' => 20,
            'units_sold_actual' => 20,
            'successful_orders' => 20,
            'orders_created' => 20,
            'captured_payments' => 20,
            'orphan_payments' => 0,
            'data_integrity_pass' => true,
            'integrity_notes' => 'All invariants held under concurrent load.',
        ];

        $report = $builder->build(
            scenario: StressTestScenario::safeAcid(),
            productId: 1,
            quantity: 1,
            users: 100,
            baseUrl: 'http://127.0.0.1:8000',
            runMetrics: $runMetrics,
            integrity: $integrity,
        );

        $this->assertSame(100, $report['total_requests']);
        $this->assertSame(20, $report['success_requests']);
        $this->assertSame(2, $report['failed_requests']);
        $this->assertSame(45.5, $report['average_response_time_ms']);
        $this->assertFalse($report['system_crashed']);
        $this->assertTrue($report['data_integrity_pass']);
        $this->assertNotEmpty($report['explanation']);
    }

    public function test_concurrent_runner_aggregates_pool_responses(): void
    {
        Http::fake([
            '*' => Http::response(
                ['message' => 'Purchased with ACID checkout', 'transaction_mode' => 'acid'],
                200,
                ['X-Response-Time-Ms' => '18.25']
            ),
        ]);

        $runner = app(ConcurrentStressRunner::class);

        $result = $runner->run(
            baseUrl: 'http://127.0.0.1:8000',
            path: '/api/checkout/acid',
            productId: 1,
            quantity: 1,
            users: 100,
            timeoutSeconds: 5,
        );

        $this->assertSame(100, $result['total_requests']);
        $this->assertSame(100, $result['success_requests']);
        $this->assertSame(0, $result['failed_requests']);
        $this->assertFalse($result['system_crashed']);
        $this->assertSame(18.25, $result['average_response_time_ms']);
        Http::assertSentCount(100);
    }

    public function test_concurrent_runner_marks_system_crashed_on_connection_failures(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $runner = app(ConcurrentStressRunner::class);

        $result = $runner->run(
            baseUrl: 'http://127.0.0.1:8000',
            path: '/api/checkout/acid',
            productId: 1,
            quantity: 1,
            users: 10,
            timeoutSeconds: 5,
        );

        $this->assertTrue($result['system_crashed']);
        $this->assertSame(10, $result['failed_requests']);
        $this->assertSame(0, $result['success_requests']);
    }

    public function test_stress_last_report_endpoint_returns_empty_when_no_run(): void
    {
        $response = $this->getJson('/api/stress/last-report');

        $response->assertOk()
            ->assertJsonPath('report', null);
    }

    public function test_stress_reset_clears_metrics(): void
    {
        $metrics = app(StressTestMetrics::class);
        $metrics->record(['scenario' => 'safe']);

        $this->postJson('/api/stress/reset')
            ->assertOk()
            ->assertJsonPath('metrics.runs_completed', 0);
    }

    public function test_integrity_snapshot_tracks_orphan_payments(): void
    {
        $product = Product::query()->create([
            'name' => 'Stress Product',
            'stock' => 10,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        \App\Models\Payment::query()->create([
            'product_id' => $product->id,
            'amount_cents' => 1000,
            'status' => 'captured',
            'payment_reference' => 'pay_orphan_test',
            'order_id' => null,
        ]);

        $snapshot = app(StressTestIntegrityChecker::class)->snapshot($product->id);

        $this->assertSame(1, $snapshot['orphan_payments']);
    }
}
