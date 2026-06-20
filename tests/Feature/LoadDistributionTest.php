<?php

namespace Tests\Feature;

use App\Models\LoadDistributionHit;
use App\Services\LoadBalancing\BackendHealthRegistry;
use App\Services\LoadBalancing\RoundRobinLoadBalancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_route_balanced_works_when_round_robin_cache_key_is_missing(): void
    {
        Cache::forget('load_balancer:rr_index');

        $response = $this->postJson('/api/load/route-balanced');

        $response->assertOk()
            ->assertJsonFragment(['distribution_mode' => 'round_robin'])
            ->assertJsonFragment(['target_server' => 'server-1']);
    }

    public function test_process_balanced_works_when_round_robin_cache_key_is_missing(): void
    {
        Cache::forget('load_balancer:rr_index');
        $this->fakeWorkerHttpResponses();

        $response = $this->postJson('/api/load/process-balanced', ['task_number' => 1]);

        $response->assertOk()
            ->assertJsonPath('distribution_mode', 'round_robin')
            ->assertJsonPath('target_port', 8000);
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

    public function test_route_balanced_stores_configured_port_per_server(): void
    {
        $this->postJson('/api/load/route-balanced')->assertOk();
        $this->postJson('/api/load/route-balanced')->assertOk();
        $this->postJson('/api/load/route-balanced')->assertOk();

        $response = $this->getJson('/api/load/distribution-stats');

        $response->assertOk()
            ->assertJsonPath('recent_hits.0.target_port', 8000)
            ->assertJsonPath('recent_hits.1.target_port', 8001)
            ->assertJsonPath('recent_hits.2.target_port', 8002);
    }

    public function test_process_balanced_stores_configured_port_not_worker_default(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'node_id' => 'server-2',
                'node_port' => 8000,
                'handled_by' => 'node on port 8000',
            ], 200);
        });

        config([
            'load_balancing.node_id' => 'server-1',
            'load_balancing.node_port' => 8000,
        ]);

        Cache::put('load_balancer:rr_index', 1, 3600);

        $response = $this->postJson('/api/load/process-balanced', ['task_number' => 1]);

        $response->assertOk()
            ->assertJsonPath('target_server', 'server-2')
            ->assertJsonPath('target_port', 8001);

        $this->assertSame(8001, (int) LoadDistributionHit::query()->value('target_port'));
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
            ->assertJsonPath('total_hits', 3)
            ->assertJsonStructure([
                'servers',
                'recent_hits',
                'rotation_sequence',
                'mode_breakdown_enriched',
                'demo_request_delay_ms',
            ])
            ->assertJsonCount(3, 'recent_hits')
            ->assertJsonCount(3, 'servers');
    }

    public function test_route_requests_assign_sequential_request_index(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/load/route-single')->assertOk();
        }

        $this->assertSame([1, 2, 3], LoadDistributionHit::query()->orderBy('id')->pluck('request_index')->all());
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

    public function test_process_worker_returns_current_node_identity(): void
    {
        config([
            'load_balancing.node_id' => 'server-2',
            'load_balancing.node_port' => 8001,
        ]);

        $this->postJson('/api/load/process', ['task_number' => 5])
            ->assertOk()
            ->assertJsonPath('task_number', 5)
            ->assertJsonPath('node_id', 'server-2')
            ->assertJsonPath('node_port', 8001)
            ->assertJsonPath('handled_by', 'node on port 8001');
    }

    public function test_process_web_alias_matches_worker_endpoint(): void
    {
        config([
            'load_balancing.node_id' => 'server-3',
            'load_balancing.node_port' => 8002,
        ]);

        $this->post('/process', ['task_number' => 2])
            ->assertOk()
            ->assertJsonPath('node_port', 8002);
    }

    public function test_process_single_uses_in_process_on_same_port_without_deadlock(): void
    {
        Http::fake();

        config([
            'load_balancing.node_id' => 'server-1',
            'load_balancing.node_port' => 8000,
        ]);

        $response = $this->postJson('/api/load/process-single', ['task_number' => 7]);

        $response->assertOk()
            ->assertJsonPath('distribution_mode', 'single')
            ->assertJsonPath('target_port', 8000)
            ->assertJsonPath('forwarded_in_process', true);

        Http::assertNothingSent();
    }

    public function test_process_balanced_forwards_to_workers_in_round_robin_order(): void
    {
        $this->fakeWorkerHttpResponses();

        $ports = [];
        for ($task = 1; $task <= 4; $task++) {
            $response = $this->postJson('/api/load/process-balanced', ['task_number' => $task]);
            $response->assertOk()
                ->assertJsonPath('distribution_mode', 'round_robin');
            $ports[] = $response->json('target_port');
        }

        $this->assertSame([8000, 8001, 8002, 8000], $ports);
        $this->assertSame(4, LoadDistributionHit::query()->where('distribution_mode', 'round_robin')->count());
    }

    public function test_process_single_always_forwards_to_port_8000(): void
    {
        $this->fakeWorkerHttpResponses();

        for ($task = 1; $task <= 3; $task++) {
            $response = $this->postJson('/api/load/process-single', ['task_number' => $task]);
            $response->assertOk()
                ->assertJsonPath('distribution_mode', 'single')
                ->assertJsonPath('target_port', 8000);
        }
    }

    public function test_load_multi_server_command_prints_task_port_lines(): void
    {
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), '/api/load/process-balanced')) {
                return Http::response([], 404);
            }

            $task = (int) ($request->data()['task_number'] ?? 1);
            $ports = [8000, 8001, 8002];
            $port = $ports[($task - 1) % 3];

            return Http::response([
                'target_port' => $port,
                'handled_by' => "node on port {$port}",
            ], 200);
        });

        $this->artisan('load:multi-server', ['--tasks' => 4, '--mode' => 'balanced'])
            ->assertSuccessful()
            ->expectsOutput('Task 1 -> Handled by node on port 8000')
            ->expectsOutput('Task 2 -> Handled by node on port 8001')
            ->expectsOutput('Task 3 -> Handled by node on port 8002')
            ->expectsOutput('Task 4 -> Handled by node on port 8000');
    }

    /**
     * @return void
     */
    private function fakeWorkerHttpResponses(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, ':8000')) {
                $port = 8000;
                $id = 'server-1';
            } elseif (str_contains($url, ':8001')) {
                $port = 8001;
                $id = 'server-2';
            } elseif (str_contains($url, ':8002')) {
                $port = 8002;
                $id = 'server-3';
            } else {
                return Http::response(['message' => 'not found'], 404);
            }

            $taskNumber = (int) ($request->data()['task_number'] ?? 1);

            return Http::response([
                'message' => 'Task processed on this node',
                'task_number' => $taskNumber,
                'node_id' => $id,
                'node_port' => $port,
                'handled_by' => "node on port {$port}",
            ], 200);
        });
    }
}
