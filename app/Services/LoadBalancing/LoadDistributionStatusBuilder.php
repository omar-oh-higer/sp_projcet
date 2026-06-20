<?php

namespace App\Services\LoadBalancing;

use App\Models\LoadDistributionHit;
use Illuminate\Support\Facades\Cache;

/** Builds lecture-style load distribution view for /demo. */
class LoadDistributionStatusBuilder
{
    /**
     * @param  array{
     *     distribution_mode_breakdown: array<string, int>,
     *     by_server: array<string, int>,
     *     strategy_used_for_balanced: string,
     *     backend_health: array<string, bool>,
     *     total_hits: int
     * }  $baseStats
     * @return array<string, mixed>
     */
    public function build(array $baseStats, BackendHealthRegistry $healthRegistry): array
    {
        $totalHits = (int) ($baseStats['total_hits'] ?? 0);
        $byServer = $baseStats['by_server'] ?? [];
        $modeBreakdown = $baseStats['distribution_mode_breakdown'] ?? [];

        $recentHits = LoadDistributionHit::query()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->reverse()
            ->values();

        $portMap = $this->backendPortMap();

        $recentHitsPayload = $recentHits->map(function (LoadDistributionHit $hit) use ($portMap) {
            $port = $this->resolvePort($hit, $portMap);

            return [
                'request_index' => $hit->request_index,
                'target_server' => $hit->target_server,
                'target_port' => $port,
                'distribution_mode' => $hit->distribution_mode,
                'recorded_at' => $hit->created_at?->toIso8601String(),
                'message_en' => $this->hitMessageEn($hit, $port),
                'message_ar' => $this->hitMessageAr($hit, $port),
            ];
        })->values()->all();

        $rotationSequence = LoadDistributionHit::query()
            ->where('distribution_mode', 'round_robin')
            ->orderBy('id')
            ->pluck('target_server')
            ->values()
            ->all();

        $servers = $this->buildServers($byServer, $baseStats['backend_health'] ?? [], $totalHits);

        $singleHits = (int) ($modeBreakdown['single'] ?? 0);
        $singleConcentration = null;

        if ($singleHits > 0) {
            $singleTarget = (string) config('load_balancing.single_target', 'server-1');
            $singleServerHits = (int) ($byServer[$singleTarget] ?? 0);
            $singleConcentration = [
                'server' => $singleTarget,
                'hits' => $singleServerHits,
                'percent' => $totalHits > 0 ? round(($singleServerHits / $totalHits) * 100, 1) : 0,
            ];
        }

        $modeBreakdownEnriched = [];

        foreach ($modeBreakdown as $mode => $count) {
            $modeBreakdownEnriched[$mode] = [
                'count' => (int) $count,
                'percent' => $totalHits > 0 ? round(((int) $count / $totalHits) * 100, 1) : 0,
            ];
        }

        $lastHit = $recentHits->last();

        return [
            'servers' => $servers,
            'recent_hits' => $recentHitsPayload,
            'rotation_sequence' => $rotationSequence,
            'mode_breakdown_enriched' => $modeBreakdownEnriched,
            'single_server_concentration' => $singleConcentration,
            'last_hit_server' => $lastHit?->target_server,
            'next_backend_hint' => $this->nextBackendHint($healthRegistry),
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, int>  $byServer
     * @param  array<string, bool>  $health
     * @return list<array<string, mixed>>
     */
    private function buildServers(array $byServer, array $health, int $totalHits): array
    {
        $pool = app(BackendPool::class);
        $servers = [];

        foreach ($pool->all() as $backend) {
            $id = $backend['id'];
            $hits = (int) ($byServer[$id] ?? 0);
            $healthy = (bool) ($health[$id] ?? true);

            $servers[] = [
                'id' => $id,
                'port' => $backend['port'],
                'label' => "{$id} :{$backend['port']}",
                'healthy' => $healthy,
                'hits' => $hits,
                'share_percent' => $totalHits > 0 ? round(($hits / $totalHits) * 100, 1) : 0,
            ];
        }

        return $servers;
    }

    private function nextBackendHint(BackendHealthRegistry $healthRegistry): ?string
    {
        $pool = $healthRegistry->healthyBackendIds();

        if ($pool === []) {
            return null;
        }

        $key = (string) config('load_balancing.cache_keys.round_robin_index', 'load_balancer:rr_index');
        $index = max((int) Cache::get($key, 0), 0);
        $poolSize = count($pool);
        $position = (($index) % $poolSize + $poolSize) % $poolSize;

        return $pool[$position];
    }

    /** @return array<string, int> */
    private function backendPortMap(): array
    {
        $map = [];

        foreach (app(BackendPool::class)->all() as $backend) {
            $map[$backend['id']] = (int) $backend['port'];
        }

        return $map;
    }

    /** @param  array<string, int>  $portMap */
    private function resolvePort(LoadDistributionHit $hit, array $portMap): ?int
    {
        $configured = $portMap[$hit->target_server] ?? null;

        if ($configured !== null) {
            return $configured;
        }

        return $hit->target_port !== null ? (int) $hit->target_port : null;
    }

    private function hitMessageEn(LoadDistributionHit $hit, ?int $port = null): string
    {
        if ($hit->distribution_mode === 'single') {
            $portSuffix = $port ? " (port {$port})" : '';

            return "Vertical scaling — pinned to {$hit->target_server}{$portSuffix}";
        }

        $portSuffix = $port ? " (port {$port})" : '';

        return "Round Robin → {$hit->target_server}{$portSuffix}";
    }

    private function hitMessageAr(LoadDistributionHit $hit, ?int $port = null): string
    {
        if ($hit->distribution_mode === 'single') {
            return "توسع عمودي — كل الحركة إلى {$hit->target_server}";
        }

        $portSuffix = $port ? " (منفذ {$port})" : '';

        return "Round Robin → {$hit->target_server}{$portSuffix}";
    }
}
