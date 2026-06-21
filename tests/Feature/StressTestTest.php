<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Services\StressTesting\ConcurrentStressRunner;
use App\Services\StressTesting\StressTestIntegrityChecker;
use App\Services\StressTesting\StressTestMetrics;
use App\Services\StressTesting\StressTestOrchestrator;
use App\Services\StressTesting\StressTestReportBuilder;
use App\Services\StressTesting\StressTestScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class StressTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(StressTestMetrics::class)->reset();

        $jsonPath = (string) config('stress_testing.report_json_path');
        if (File::exists($jsonPath)) {
            File::delete($jsonPath);
        }
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

    public function test_integrity_checker_fails_unsafe_when_orphans_present(): void
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
            'stock' => 0,
            'successful_orders' => 10,
            'captured_payments' => 15,
            'orphan_payments' => 5,
        ];

        $result = $checker->evaluate(
            before: $before,
            after: $after,
            successRequests: 10,
            quantity: 1,
            scenario: StressTestScenario::unsafeNonAtomic(),
        );

        $this->assertFalse($result['data_integrity_pass']);
    }

    public function test_unsafe_process_pool_runner_aggregates_worker_results(): void
    {
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(output: json_encode([
                    'http_status' => 200,
                    'duration_ms' => 12.5,
                    'result' => ['status' => 'success', 'transaction_mode' => 'non_atomic'],
                ])))
                ->push(Process::result(output: json_encode([
                    'http_status' => 500,
                    'duration_ms' => 18.0,
                    'result' => ['status' => 'simulated_failure', 'transaction_mode' => 'non_atomic'],
                ])))
                ->push(Process::result(output: json_encode([
                    'http_status' => 409,
                    'duration_ms' => 9.0,
                    'result' => ['status' => 'insufficient_stock', 'transaction_mode' => 'non_atomic'],
                ]))),
        ]);

        $runner = app(ConcurrentStressRunner::class);

        $result = $runner->runViaProcessWorkers(
            scenario: StressTestScenario::unsafeNonAtomic(),
            productId: 1,
            quantity: 1,
            users: 3,
        );

        $this->assertSame(3, $result['total_requests']);
        $this->assertSame(1, $result['success_requests']);
        $this->assertSame(1, $result['failed_requests']);
        $this->assertSame(1, $result['rejected_requests']);
        $this->assertFalse($result['system_crashed']);
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
        $metrics->record([
            'scenario' => 'safe',
            'scenario_label' => 'Safe',
            'concurrent_users' => 10,
            'success_requests' => 5,
            'failed_requests' => 0,
            'rejected_requests' => 5,
            'data_integrity_pass' => true,
            'integrity' => ['orphan_payments' => 0],
            'system_crashed' => false,
        ]);

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

        Payment::query()->create([
            'product_id' => $product->id,
            'amount_cents' => 1000,
            'status' => 'captured',
            'payment_reference' => 'pay_orphan_test',
            'order_id' => null,
        ]);

        $snapshot = app(StressTestIntegrityChecker::class)->snapshot($product->id);

        $this->assertSame(1, $snapshot['orphan_payments']);
    }

    public function test_stress_stats_includes_enriched_demo_fields(): void
    {
        $product = Product::query()->create([
            'name' => 'Stats Product',
            'stock' => 10,
            'price_cents' => 500,
            'version' => 0,
        ]);

        $response = $this->getJson('/api/stress/stats?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonStructure([
                'metrics',
                'recent_runs',
                'db_audit' => ['stock', 'orphan_payments'],
                'scenario_summary',
                'product_snapshot',
                'demo_users',
                'demo_users_max',
                'demo_stock',
                'demo_request_delay_ms',
                'last_concurrent_users',
                'demo_run_in_progress',
                'configured_base_url',
                'effective_base_url',
                'base_url_mismatch',
                'refreshed_at',
            ])
            ->assertJsonPath('server_reachable', true)
            ->assertJsonPath('demo_users_max', (int) config('stress_testing.demo_users_max', 100))
            ->assertJsonPath('demo_stock', (int) config('stress_testing.demo_stock', 10));
    }

    public function test_demo_reset_restores_stock_and_clears_orphan_payments(): void
    {
        $demoStock = (int) config('stress_testing.demo_stock', 10);

        $product = Product::query()->create([
            'name' => 'Reset Product',
            'stock' => 2,
            'price_cents' => 800,
            'version' => 3,
        ]);

        Payment::query()->create([
            'product_id' => $product->id,
            'amount_cents' => 800,
            'status' => 'captured',
            'payment_reference' => 'pay_stress_orphan',
            'order_id' => null,
        ]);

        $response = $this->postJson('/api/stress/demo-reset', [
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
    }

    public function test_demo_reset_with_reset_metrics_false_keeps_run_log(): void
    {
        $product = Product::query()->create([
            'name' => 'Keep Log Product',
            'stock' => 10,
            'price_cents' => 600,
            'version' => 0,
        ]);

        app(StressTestMetrics::class)->record([
            'scenario' => 'unsafe',
            'scenario_label' => 'Unsafe',
            'concurrent_users' => 5,
            'success_requests' => 3,
            'failed_requests' => 0,
            'rejected_requests' => 2,
            'data_integrity_pass' => false,
            'integrity' => ['orphan_payments' => 1],
            'system_crashed' => false,
        ]);

        $beforeReset = $this->getJson('/api/stress/stats?product_id='.$product->id);
        $logCountBefore = count($beforeReset->json('recent_runs'));
        $this->assertGreaterThan(0, $logCountBefore);

        $this->postJson('/api/stress/demo-reset', [
            'product_id' => $product->id,
            'reset_metrics' => false,
        ])->assertOk();

        $afterReset = $this->getJson('/api/stress/stats?product_id='.$product->id);
        $this->assertSame($logCountBefore, count($afterReset->json('recent_runs')));
    }

    public function test_metrics_persist_across_separate_http_requests(): void
    {
        app(StressTestMetrics::class)->record([
            'scenario' => 'safe',
            'scenario_label' => 'Safe',
            'concurrent_users' => 10,
            'success_requests' => 8,
            'failed_requests' => 0,
            'rejected_requests' => 2,
            'data_integrity_pass' => true,
            'integrity' => ['orphan_payments' => 0],
            'system_crashed' => false,
        ]);

        $this->app->forgetInstance(StressTestMetrics::class);

        $response = $this->getJson('/api/stress/stats');

        $response->assertOk()
            ->assertJsonPath('metrics.runs_completed', 1);
    }

    public function test_last_report_exposes_scenarios_shape_from_cache(): void
    {
        $metrics = app(StressTestMetrics::class);
        $metrics->recordCombinedReport([
            'task' => 'Task 9 — Concurrent Stress Test',
            'executed_at' => now()->toIso8601String(),
            'scenarios' => [
                [
                    'scenario' => 'unsafe',
                    'success_requests' => 5,
                    'failed_requests' => 1,
                    'data_integrity_pass' => false,
                ],
            ],
        ]);

        $response = $this->getJson('/api/stress/last-report');

        $response->assertOk()
            ->assertJsonPath('report.scenarios.0.scenario', 'unsafe')
            ->assertJsonPath('report.scenarios.0.success_requests', 5);
    }

    public function test_orchestrator_records_combined_report_in_cache(): void
    {
        Http::fake([
            '*' => Http::response(['transaction_mode' => 'acid'], 200),
        ]);

        $product = Product::query()->create([
            'name' => 'Orchestrator Product',
            'stock' => 10,
            'price_cents' => 1000,
            'version' => 0,
        ]);

        app(StressTestOrchestrator::class)->runScenarios(
            productId: $product->id,
            quantity: 1,
            users: 5,
            baseUrl: 'http://127.0.0.1:8000',
            scenarioKey: 'safe',
            writeOutput: 'none',
        );

        $this->app->forgetInstance(StressTestMetrics::class);

        $stats = $this->getJson('/api/stress/stats?product_id='.$product->id);
        $stats->assertOk()
            ->assertJsonPath('metrics.runs_completed', 1)
            ->assertJsonPath('last_report.scenarios.0.scenario', 'safe');
    }

    public function test_demo_run_returns_202_and_starts_background_process(): void
    {
        Process::fake();

        $product = Product::query()->create([
            'name' => 'Demo Run Product',
            'stock' => 10,
            'price_cents' => 900,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/stress/demo-run', [
            'product_id' => $product->id,
            'scenario' => 'safe',
            'quantity' => 1,
            'base_url' => 'http://127.0.0.1:8000',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('demo_run_in_progress', true)
            ->assertJsonPath('base_url', 'http://127.0.0.1:8000');

        $this->assertTrue(app(StressTestMetrics::class)->isDemoRunInProgress());

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, 'stress:concurrent')
                && str_contains($command, '--scenario=safe')
                && str_contains($command, '--baseUrl=http://127.0.0.1:8000');
        });
    }

    public function test_demo_run_passes_users_parameter(): void
    {
        Process::fake();

        $product = Product::query()->create([
            'name' => 'Users Param Product',
            'stock' => 10,
            'price_cents' => 900,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/stress/demo-run', [
            'product_id' => $product->id,
            'scenario' => 'unsafe',
            'quantity' => 1,
            'users' => 100,
            'base_url' => 'http://127.0.0.1:8000',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('concurrent_users', 100);

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, '--users=100');
        });
    }

    public function test_demo_run_clamps_users_to_max(): void
    {
        Process::fake();

        config(['stress_testing.demo_users_max' => 100]);

        $product = Product::query()->create([
            'name' => 'Clamp Users Product',
            'stock' => 10,
            'price_cents' => 900,
            'version' => 0,
        ]);

        $response = $this->postJson('/api/stress/demo-run', [
            'product_id' => $product->id,
            'scenario' => 'safe',
            'quantity' => 1,
            'users' => 150,
            'base_url' => 'http://127.0.0.1:8000',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('concurrent_users', 100);

        Process::assertRan(function ($process) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return str_contains($command, '--users=100')
                && ! str_contains($command, '--users=150');
        });
    }

    public function test_demo_run_rejects_when_lock_active(): void
    {
        Process::fake();

        $product = Product::query()->create([
            'name' => 'Lock Product',
            'stock' => 10,
            'price_cents' => 900,
            'version' => 0,
        ]);

        app(StressTestMetrics::class)->markDemoRunStarted([
            'scenario' => 'unsafe',
            'concurrent_users' => 10,
        ]);

        $response = $this->postJson('/api/stress/demo-run', [
            'product_id' => $product->id,
            'scenario' => 'unsafe',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('demo_run_in_progress', true);

        Process::assertNothingRan();
    }

    public function test_stress_command_clears_demo_run_lock(): void
    {
        Http::fake([
            '*' => Http::response(['transaction_mode' => 'acid', 'order_id' => 1], 200),
        ]);

        $product = Product::query()->create([
            'name' => 'Clear Lock Product',
            'stock' => 10,
            'price_cents' => 900,
            'version' => 0,
        ]);

        app(StressTestMetrics::class)->markDemoRunStarted([
            'scenario' => 'safe',
            'concurrent_users' => 3,
        ]);

        $this->artisan('stress:concurrent', [
            '--users' => 3,
            '--product' => $product->id,
            '--scenario' => 'safe',
            '--output' => 'none',
        ])->assertSuccessful();

        $this->assertFalse(app(StressTestMetrics::class)->isDemoRunInProgress());
    }

    public function test_stale_demo_run_lock_is_cleared_when_no_runs_recorded(): void
    {
        config(['stress_testing.demo_run_stale_seconds' => 1]);

        $metrics = app(StressTestMetrics::class);
        $metrics->markDemoRunStarted([
            'scenario' => 'unsafe',
            'concurrent_users' => 10,
            'runs_before' => 0,
        ]);

        sleep(2);

        $this->assertFalse($metrics->isDemoRunInProgress());
    }

    public function test_stats_shows_connection_failure_hint_when_all_requests_fail(): void
    {
        $product = Product::query()->create([
            'name' => 'Connection Fail Product',
            'stock' => 10,
            'price_cents' => 500,
            'version' => 0,
        ]);

        app(StressTestMetrics::class)->record([
            'scenario' => 'unsafe',
            'scenario_label' => 'Unsafe',
            'concurrent_users' => 100,
            'total_requests' => 100,
            'success_requests' => 0,
            'failed_requests' => 100,
            'rejected_requests' => 0,
            'connection_errors' => 100,
            'data_integrity_pass' => true,
            'integrity' => ['orphan_payments' => 0],
            'system_crashed' => true,
            'product_id' => $product->id,
        ]);

        $this->app->forgetInstance(StressTestMetrics::class);

        $response = $this->getJson('/api/stress/stats?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('connection_failure_hint_en', fn ($value) => is_string($value) && str_contains($value, 'CONNECTION FAILURE'))
            ->assertJsonPath('recent_runs.0.message_en', fn ($value) => is_string($value) && str_contains($value, 'CONNECTION FAILURE'));
    }

    public function test_metrics_snapshot_includes_last_concurrent_users(): void
    {
        $product = Product::query()->create([
            'name' => 'Last N Product',
            'stock' => 10,
            'price_cents' => 500,
            'version' => 0,
        ]);

        app(StressTestMetrics::class)->record([
            'scenario' => 'safe',
            'scenario_label' => 'Safe ACID',
            'concurrent_users' => 100,
            'success_requests' => 10,
            'failed_requests' => 90,
            'rejected_requests' => 90,
            'data_integrity_pass' => true,
            'integrity' => ['orphan_payments' => 0],
            'product_id' => $product->id,
        ]);

        $this->app->forgetInstance(StressTestMetrics::class);

        $response = $this->getJson('/api/stress/stats?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('last_concurrent_users', 100)
            ->assertJsonPath('metrics.last_concurrent_users', 100)
            ->assertJsonPath('scenario_summary.last_concurrent_users', 100)
            ->assertJsonPath('scenario_summary.safe_concurrent_users', 100);
    }
}
