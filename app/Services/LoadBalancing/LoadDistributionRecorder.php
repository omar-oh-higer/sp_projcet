<?php

namespace App\Services\LoadBalancing;

use App\Models\LoadDistributionHit;
use Illuminate\Support\Facades\DB;

/** Persists each routing decision and aggregates stats for the demo API. */
class LoadDistributionRecorder
{
    public function record(string $targetServer, string $distributionMode, ?int $requestIndex = null): void
    {
        LoadDistributionHit::query()->create([
            'target_server' => $targetServer,
            'distribution_mode' => $distributionMode,
            'request_index' => $requestIndex,
        ]);
    }

    /**
     * @return array{
     *     distribution_mode_breakdown: array<string, int>,
     *     by_server: array<string, int>,
     *     strategy_used_for_balanced: string,
     *     backend_health: array<string, bool>,
     *     total_hits: int
     * }
     */
    public function stats(BackendHealthRegistry $healthRegistry): array
    {
        $modeBreakdown = LoadDistributionHit::query()
            ->select('distribution_mode', DB::raw('count(*) as total'))
            ->groupBy('distribution_mode')
            ->pluck('total', 'distribution_mode')
            ->all();

        $byServer = LoadDistributionHit::query()
            ->select('target_server', DB::raw('count(*) as total'))
            ->groupBy('target_server')
            ->pluck('total', 'target_server')
            ->all();

        foreach ($healthRegistry->allBackendIds() as $id) {
            $byServer[$id] = (int) ($byServer[$id] ?? 0);
        }

        return [
            'distribution_mode_breakdown' => array_map('intval', $modeBreakdown),
            'by_server' => array_map('intval', $byServer),
            'strategy_used_for_balanced' => (string) config('load_balancing.default_strategy', 'round_robin'),
            'backend_health' => $healthRegistry->healthSnapshot(),
            'total_hits' => (int) LoadDistributionHit::query()->count(),
        ];
    }

    public function reset(): void
    {
        LoadDistributionHit::query()->delete();
    }
}
