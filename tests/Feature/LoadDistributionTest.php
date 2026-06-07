<?php

namespace Tests\Feature;

use App\Models\LoadDistributionHit;
use App\Services\LoadBalancing\BackendHealthRegistry;
use App\Services\LoadBalancing\RoundRobinLoadBalancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoadDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetLoadBalancingState();
    }

    private function resetLoadBalancingState(): void
    {
        LoadDistributionHit::query()->delete();
        Cache::forget('load_balancer:rr_index');
        app(BackendHealthRegistry::class)->resetHealthOverrides();
        app(RoundRobinLoadBalancer::class)->resetRotation();
    }

    public function test_route_single_always_targets_server_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/load/route-single');
            $response->assertOk()
                ->assertJsonFragment(['distribution_mode' => 'single'])
                ->assertJsonFragment(['target_server' => 'server-1'])
                ->assertJsonFragment(['scaling_model' => 'vertical']);
        }

        $this->assertSame(5, LoadDistributionHit::query()->where('distribution_mode', 'single')->count());
        $this->assertSame(5, LoadDistributionHit::query()->where('target_server', 'server-1')->count());
    }

    public function test_route_balanced_cycles_through_healthy_servers(): void
    {
        $seen = [];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/load/route-balanced');
            $response->assertOk()
                ->assertJsonFragment(['distribution_mode' => 'round_robin'])
                ->assertJsonFragment(['strategy' => 'round_robin']);
            $seen[] = $response->json('target_server');
        }

        $this->assertSame(['server-1', 'server-2', 'server-3', 'server-1', 'server-2', 'server-3'], $seen);
    }

    public function test_unhealthy_server_is_skipped_by_round_robin(): void
    {
        $this->postJson('/api/load/set-server-health', [
            'server' => 'server-2',
            'healthy' => false,
        ])->assertOk();

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/load/route-balanced');
            $response->assertOk();
            $seen[] = $response->json('target_server');
        }

        $this->assertNotContains('server-2', $seen);
        $this->assertSame(['server-1', 'server-3', 'server-1', 'server-3'], $seen);
    }

    public function test_distribution_stats_reflect_recorded_hits(): void
    {
        $this->postJson('/api/load/route-single');
        $this->postJson('/api/load/route-balanced');
        $this->postJson('/api/load/route-balanced');

        $response = $this->getJson('/api/load/distribution-stats');

        $response->assertOk()
            ->assertJsonFragment(['strategy_used_for_balanced' => 'round_robin'])
            ->assertJsonPath('distribution_mode_breakdown.single', 1)
            ->assertJsonPath('distribution_mode_breakdown.round_robin', 2)
            ->assertJsonPath('total_hits', 3);
    }

    public function test_load_distribute_command_splits_traffic_evenly(): void
    {
        $this->resetLoadBalancingState();

        $this->artisan('load:distribute', ['--requests' => 9])
            ->assertSuccessful();

        $this->assertSame(3, LoadDistributionHit::query()->where('target_server', 'server-1')->count());
        $this->assertSame(3, LoadDistributionHit::query()->where('target_server', 'server-2')->count());
        $this->assertSame(3, LoadDistributionHit::query()->where('target_server', 'server-3')->count());
    }

    public function test_distribution_reset_clears_hits(): void
    {
        $this->postJson('/api/load/route-single');
        $this->postJson('/api/load/distribution-reset')->assertOk();

        $this->assertSame(0, LoadDistributionHit::query()->count());
    }
}
