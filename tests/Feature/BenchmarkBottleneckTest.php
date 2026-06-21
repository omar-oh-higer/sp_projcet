<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\Benchmarking\BenchmarkComparisonBuilder;
use App\Services\Benchmarking\BenchmarkMetrics;
use Database\Seeders\BenchmarkOrdersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BenchmarkBottleneckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'benchmarking.sequential_query_delay_ms' => 2,
            'benchmarking.sample_order_limit' => 20,
            'benchmarking.bottleneck_log_threshold_ms' => 50,
        ]);

        app(BenchmarkMetrics::class)->reset();

        Product::query()->create([
            'name' => 'Benchmark Product',
            'stock' => 100,
            'price_cents' => 1500,
            'version' => 0,
        ]);

        $this->seed(BenchmarkOrdersSeeder::class);
    }

    public function test_slow_endpoint_identifies_sequential_bottleneck(): void
    {
        $response = $this->getJson('/api/benchmark/sales-report/slow?product_id=1');

        $response->assertOk()
            ->assertHeader('X-Trace-Id')
            ->assertHeader('X-Bottleneck-Span', 'sequential_order_product_lookups')
            ->assertJsonPath('benchmark_mode', 'slow')
            ->assertJsonPath('bottleneck_span', 'sequential_order_product_lookups');

        $this->assertGreaterThan(20, $response->json('db_queries'));
        $this->assertNotNull($response->json('bottleneck_analysis'));
    }

    public function test_optimized_uses_fewer_db_queries_than_slow(): void
    {
        $slow = $this->getJson('/api/benchmark/sales-report/slow?product_id=1')->json();
        $optimized = $this->getJson('/api/benchmark/sales-report/optimized?product_id=1')->json();

        $this->assertGreaterThan($optimized['db_queries'], $slow['db_queries']);
        $this->assertLessThanOrEqual(3, $optimized['db_queries']);
    }

    public function test_optimized_is_faster_than_slow_with_demo_delay(): void
    {
        $slow = $this->getJson('/api/benchmark/sales-report/slow?product_id=1')->json();
        $optimized = $this->getJson('/api/benchmark/sales-report/optimized?product_id=1')->json();

        $this->assertLessThan($slow['total_duration_ms'], $optimized['total_duration_ms']);
    }

    public function test_comparison_builder_computes_improvement_percent(): void
    {
        $builder = app(BenchmarkComparisonBuilder::class);

        $comparison = $builder->build(
            productId: 1,
            slowSamples: [
                ['total_duration_ms' => 400, 'db_queries' => 41],
                ['total_duration_ms' => 420, 'db_queries' => 41],
            ],
            optimizedSamples: [
                ['total_duration_ms' => 30, 'db_queries' => 3],
                ['total_duration_ms' => 28, 'db_queries' => 3],
            ],
            bottleneckSpan: 'sequential_order_product_lookups',
        );

        $this->assertSame(410.0, $comparison['before']['avg_response_time_ms']);
        $this->assertSame(29.0, $comparison['after']['avg_response_time_ms']);
        $this->assertGreaterThan(90, $comparison['improvement']['response_time_percent_faster']);
        $this->assertGreaterThan(90, $comparison['improvement']['db_queries_percent_fewer']);
    }

    public function test_comparison_endpoint_returns_last_comparison(): void
    {
        app(BenchmarkMetrics::class)->recordComparison([
            'before' => ['avg_response_time_ms' => 100],
            'after' => ['avg_response_time_ms' => 10],
        ]);

        $this->getJson('/api/benchmark/comparison')
            ->assertOk()
            ->assertJsonPath('comparison.before.avg_response_time_ms', 100);
    }

    public function test_benchmark_stats_includes_enriched_demo_fields(): void
    {
        $response = $this->getJson('/api/benchmark/stats?product_id=1');

        $response->assertOk()
            ->assertJsonStructure([
                'metrics',
                'comparison',
                'recent_runs',
                'db_traces',
                'order_count',
                'ready_for_demo',
                'demo_iterations',
                'demo_iterations_max',
                'sample_order_limit',
                'product_snapshot',
                'has_comparison',
                'refreshed_at',
            ])
            ->assertJsonPath('demo_iterations', (int) config('benchmarking.demo_iterations', 5))
            ->assertJsonPath('demo_iterations_max', (int) config('benchmarking.demo_iterations_max', 10));
    }

    public function test_demo_run_builds_comparison_with_five_iterations(): void
    {
        $response = $this->postJson('/api/benchmark/demo-run', [
            'product_id' => 1,
            'iterations' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('iterations', 5)
            ->assertJsonStructure([
                'comparison' => [
                    'before' => ['avg_response_time_ms', 'avg_db_queries'],
                    'after' => ['avg_response_time_ms', 'avg_db_queries'],
                    'improvement' => ['response_time_percent_faster', 'db_queries_percent_fewer'],
                ],
            ]);

        $comparison = $response->json('comparison');
        $this->assertGreaterThan(
            $comparison['after']['avg_db_queries'],
            $comparison['before']['avg_db_queries'],
        );
        $this->assertGreaterThan(
            $comparison['after']['avg_response_time_ms'],
            $comparison['before']['avg_response_time_ms'],
        );
        $this->assertGreaterThan(0, $comparison['improvement']['response_time_percent_faster']);

        $this->getJson('/api/benchmark/stats?product_id=1')
            ->assertOk()
            ->assertJsonPath('has_comparison', true);
    }

    public function test_demo_reset_clears_metrics_and_runs(): void
    {
        $this->postJson('/api/benchmark/demo-run', [
            'product_id' => 1,
            'iterations' => 2,
        ])->assertOk();

        $this->assertDatabaseCount('benchmark_runs', 4);

        $this->postJson('/api/benchmark/demo-reset', [
            'product_id' => 1,
            'ensure_seed' => true,
        ])
            ->assertOk()
            ->assertJsonPath('metrics_reset', true);

        $this->assertDatabaseCount('benchmark_runs', 0);

        $this->getJson('/api/benchmark/stats?product_id=1')
            ->assertOk()
            ->assertJsonPath('has_comparison', false)
            ->assertJsonPath('metrics.runs_completed', 0);
    }

    public function test_stats_shows_seed_hint_when_few_orders(): void
    {
        Order::query()->delete();

        $response = $this->getJson('/api/benchmark/stats?product_id=1');

        $response->assertOk()
            ->assertJsonPath('ready_for_demo', false)
            ->assertJsonPath('order_count', 0)
            ->assertJsonPath('seed_hint_en', fn ($value) => is_string($value) && str_contains($value, 'BenchmarkOrdersSeeder'));
    }

    public function test_traces_endpoint_lists_persisted_runs(): void
    {
        $this->getJson('/api/benchmark/sales-report/slow?product_id=1')->assertOk();
        $this->getJson('/api/benchmark/sales-report/optimized?product_id=1')->assertOk();

        $response = $this->getJson('/api/benchmark/traces');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, count($response->json('traces')));
    }

    public function test_benchmark_reset_clears_runs(): void
    {
        $this->getJson('/api/benchmark/sales-report/slow?product_id=1')->assertOk();

        $this->postJson('/api/benchmark/reset')
            ->assertOk()
            ->assertJsonPath('metrics.runs_completed', 0);

        $this->assertDatabaseCount('benchmark_runs', 0);
    }
}
