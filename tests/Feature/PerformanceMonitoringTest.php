<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailySalesTallyJob;
use App\Models\PerformanceMeasurement;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_middleware_adds_response_time_header_and_persists_measurement(): void
    {
        Product::query()->create([
            'name' => 'Perf Product',
            'stock' => 5,
        ]);

        $response = $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ]);

        $response->assertOk();
        $response->assertHeader('X-Response-Time-Ms');

        $this->assertDatabaseHas('performance_measurements', [
            'channel' => 'http',
            'name' => 'api/buy-without-lock',
        ]);

        $measurement = PerformanceMeasurement::query()->where('channel', 'http')->first();
        $this->assertNotNull($measurement);
        $this->assertGreaterThan(0, (float) $measurement->duration_ms);
        $this->assertSame(200, $measurement->status_code);
    }

    public function test_performance_stats_endpoint_returns_aggregates(): void
    {
        Product::query()->create([
            'name' => 'Stats Product',
            'stock' => 3,
        ]);

        $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $response = $this->getJson('/api/performance/stats');

        $response->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('summary.total_measurements', 1)
            ->assertJsonStructure([
                'summary' => ['total_measurements', 'avg_duration_ms', 'max_duration_ms', 'slow_count'],
                'by_channel',
                'recent',
            ]);

        $this->assertSame(1, PerformanceMeasurement::query()->count());
    }

    public function test_performance_routes_excluded_from_self_recording(): void
    {
        Product::query()->create([
            'name' => 'Exclude Product',
            'stock' => 2,
        ]);

        $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->assertSame(1, PerformanceMeasurement::query()->count());

        $stats = $this->getJson('/api/performance/stats');
        $stats->assertOk();
        $stats->assertHeader('X-Response-Time-Ms');

        $this->assertSame(1, PerformanceMeasurement::query()->count());
        $this->assertDatabaseMissing('performance_measurements', [
            'name' => 'api/performance/stats',
        ]);

        $reset = $this->postJson('/api/performance/reset');
        $reset->assertOk();
        $reset->assertHeader('X-Response-Time-Ms');

        $this->assertDatabaseMissing('performance_measurements', [
            'name' => 'api/performance/reset',
        ]);
    }

    public function test_stats_includes_enriched_fields(): void
    {
        Product::query()->create([
            'name' => 'Enriched Product',
            'stock' => 5,
        ]);

        $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $response = $this->getJson('/api/performance/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'has_measurements',
                'top_routes',
                'slowest_recent',
                'aspect_message_en',
                'aspect_message_ar',
                'demo_probe_endpoints',
                'refreshed_at',
            ])
            ->assertJsonPath('has_measurements', true);

        $this->assertNotEmpty($response->json('aspect_message_en'));
        $this->assertNotEmpty($response->json('aspect_message_ar'));
        $this->assertNotEmpty($response->json('top_routes'));
    }

    public function test_demo_reset_clears_all_measurements(): void
    {
        Product::query()->create([
            'name' => 'Reset Product',
            'stock' => 2,
        ]);

        $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('performance_measurements', [
            'channel' => 'http',
            'name' => 'api/buy-without-lock',
        ]);

        $this->postJson('/api/performance/demo-reset')->assertOk();

        $this->assertDatabaseMissing('performance_measurements', [
            'name' => 'api/buy-without-lock',
        ]);

        $this->assertSame(0, PerformanceMeasurement::query()->count());
    }

    public function test_performance_reset_clears_prior_measurements(): void
    {
        Product::query()->create([
            'name' => 'Reset Product Legacy',
            'stock' => 2,
        ]);

        $this->postJson('/api/buy-without-lock', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/performance/reset')->assertOk();

        $this->assertSame(0, PerformanceMeasurement::query()->count());
    }

    public function test_job_middleware_records_job_channel_measurement(): void
    {
        ProcessDailySalesTallyJob::dispatchSync('2026-06-07');

        $this->assertDatabaseHas('performance_measurements', [
            'channel' => 'job',
            'name' => ProcessDailySalesTallyJob::class,
        ]);
    }
}
