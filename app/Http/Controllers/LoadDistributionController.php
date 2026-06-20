<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetServerHealthRequest;
use App\Services\LoadBalancing\BackendPool;
use App\Services\LoadBalancing\BackendHealthRegistry;
use App\Services\LoadBalancing\LoadDistributionRecorder;
use App\Services\LoadBalancing\LoadDistributionStatusBuilder;
use App\Services\LoadBalancing\MultiServerProcessGateway;
use App\Services\LoadBalancing\NodeIdentity;
use App\Services\LoadBalancing\RoundRobinLoadBalancer;
use App\Services\LoadBalancing\SingleServerRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Task 5: vertical (single server) vs horizontal (Round Robin) load distribution demo. */
class LoadDistributionController extends Controller
{
    /**
     * Before (vertical scaling): all traffic pinned to one server — no balancer.
     * Simulates Black Friday spike hitting a single machine.
     */
    public function routeSingle(
        SingleServerRouter $router,
        LoadDistributionRecorder $recorder,
        BackendPool $backendPool,
    ): JsonResponse {
        $target = $router->target();
        $port = $backendPool->find($target)['port'];
        $recorder->record($target, 'single', null, $port);

        return response()->json([
            'message' => 'Request routed to single server (no load balancing).',
            'distribution_mode' => 'single',
            'target_server' => $target,
            'target_port' => $port,
            'scaling_model' => 'vertical',
        ]);
    }

    /**
     * After (horizontal scaling): Round Robin across healthy virtual backends.
     * Skips unhealthy servers (lecture health-check rotation).
     */
    public function routeBalanced(
        RoundRobinLoadBalancer $balancer,
        LoadDistributionRecorder $recorder,
        BackendPool $backendPool,
    ): JsonResponse {
        try {
            $target = $balancer->nextBackend();
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }

        $port = $backendPool->find($target)['port'];
        $recorder->record($target, 'round_robin', null, $port);

        return response()->json([
            'message' => 'Request routed via Round Robin load balancer.',
            'distribution_mode' => 'round_robin',
            'strategy' => 'round_robin',
            'target_server' => $target,
            'target_port' => $port,
            'scaling_model' => 'horizontal',
        ]);
    }

    /** Aggregated hit counts per server and mode (Postman / report proof). */
    public function stats(
        LoadDistributionRecorder $recorder,
        BackendHealthRegistry $healthRegistry,
        LoadDistributionStatusBuilder $statusBuilder,
    ): JsonResponse {
        $base = $recorder->stats($healthRegistry);
        $enriched = $statusBuilder->build($base, $healthRegistry);

        return response()->json(array_merge($base, $enriched, [
            'demo_request_delay_ms' => (int) config('load_balancing.demo_request_delay_ms', 300),
        ]));
    }

    /** Demo: mark a backend healthy/unhealthy (lecture Server B removed from rotation). */
    public function setServerHealth(
        SetServerHealthRequest $request,
        BackendHealthRegistry $healthRegistry,
    ): JsonResponse {
        $server = $request->validated('server');
        $healthy = (bool) $request->validated('healthy');

        $healthRegistry->setHealthy($server, $healthy);

        return response()->json([
            'message' => $healthy
                ? "{$server} marked healthy and eligible for Round Robin."
                : "{$server} marked unhealthy — 0% traffic until restored.",
            'server' => $server,
            'healthy' => $healthy,
            'backend_health' => $healthRegistry->healthSnapshot(),
        ]);
    }

    /** Clear recorded hits and health overrides for a fresh demo run. */
    public function reset(
        LoadDistributionRecorder $recorder,
        BackendHealthRegistry $healthRegistry,
        RoundRobinLoadBalancer $balancer,
    ): JsonResponse {
        $recorder->reset();
        $healthRegistry->resetHealthOverrides();
        $balancer->resetRotation();

        return response()->json([
            'message' => 'Load distribution demo data reset.',
        ]);
    }

    /**
     * Worker node: process a task on this instance (real multi-port demo).
     * Each `php artisan serve --port=XXXX` returns its own node_id/node_port.
     */
    public function process(Request $request, NodeIdentity $identity): JsonResponse
    {
        $payload = $request->validate([
            'task_number' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($identity->processPayload((int) $payload['task_number']));
    }

    /**
     * Gateway "before": always forward to single_target worker (port 8000).
     */
    public function processSingle(Request $request, MultiServerProcessGateway $gateway): JsonResponse
    {
        $payload = $request->validate([
            'task_number' => ['required', 'integer', 'min:1'],
        ]);

        $result = $gateway->forwardSingle((int) $payload['task_number']);

        if (isset($result['error'])) {
            return response()->json($result, $result['error'] === 'no_healthy_backends' ? 503 : 502);
        }

        return response()->json($result);
    }

    /**
     * Gateway "after": Round Robin forward to healthy worker nodes over HTTP.
     */
    public function processBalanced(Request $request, MultiServerProcessGateway $gateway): JsonResponse
    {
        $payload = $request->validate([
            'task_number' => ['required', 'integer', 'min:1'],
        ]);

        $result = $gateway->forwardBalanced((int) $payload['task_number']);

        if (isset($result['error'])) {
            return response()->json($result, $result['error'] === 'no_healthy_backends' ? 503 : 502);
        }

        return response()->json($result);
    }
}
