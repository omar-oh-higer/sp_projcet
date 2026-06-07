<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetServerHealthRequest;
use App\Services\LoadBalancing\BackendHealthRegistry;
use App\Services\LoadBalancing\LoadDistributionRecorder;
use App\Services\LoadBalancing\RoundRobinLoadBalancer;
use App\Services\LoadBalancing\SingleServerRouter;
use Illuminate\Http\JsonResponse;

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
    ): JsonResponse {
        $target = $router->target();
        $recorder->record($target, 'single');

        return response()->json([
            'message' => 'Request routed to single server (no load balancing).',
            'distribution_mode' => 'single',
            'target_server' => $target,
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
    ): JsonResponse {
        try {
            $target = $balancer->nextBackend();
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }

        $recorder->record($target, 'round_robin');

        return response()->json([
            'message' => 'Request routed via Round Robin load balancer.',
            'distribution_mode' => 'round_robin',
            'strategy' => 'round_robin',
            'target_server' => $target,
            'scaling_model' => 'horizontal',
        ]);
    }

    /** Aggregated hit counts per server and mode (Postman / report proof). */
    public function stats(
        LoadDistributionRecorder $recorder,
        BackendHealthRegistry $healthRegistry,
    ): JsonResponse {
        return response()->json($recorder->stats($healthRegistry));
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
}
