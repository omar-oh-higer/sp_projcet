<?php

namespace App\Services\PerformanceMonitoring;

use App\Models\PerformanceMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** AOP aspect core: records HTTP/job timings without polluting controllers or jobs. */
class PerformanceMonitor
{
    public function isEnabled(): bool
    {
        return (bool) config('performance_monitoring.enabled', true);
    }

    public function recordHttp(Request $request, Response $response, float $durationMs): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $path = $request->path();

        if ($this->shouldExcludeHttpPath($path)) {
            return;
        }

        $metadata = [
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
        ];

        $this->persist('http', $path, $durationMs, $response->getStatusCode(), $metadata);
        $this->logIfSlow('http', $path, $durationMs);
    }

    public function shouldExcludeHttpPath(string $path): bool
    {
        $normalized = ltrim($path, '/');
        $prefixes = config('performance_monitoring.excluded_path_prefixes', ['api/performance']);

        if (! is_array($prefixes)) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            $prefix = ltrim((string) $prefix, '/');

            if ($prefix === '') {
                continue;
            }

            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public function recordJob(string $jobClass, float $durationMs, ?Throwable $exception = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $metadata = [
            'failed' => $exception !== null,
            'error' => $exception?->getMessage(),
        ];

        $this->persist('job', $jobClass, $durationMs, null, $metadata);
        $this->logIfSlow('job', $jobClass, $durationMs, $exception !== null);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $slowThreshold = (float) config('performance_monitoring.slow_threshold_ms', 500);
        $recentLimit = (int) config('performance_monitoring.recent_limit', 50);

        $query = PerformanceMeasurement::query();

        $total = (clone $query)->count();
        $avgDuration = (float) ((clone $query)->avg('duration_ms') ?? 0);
        $maxDuration = (float) ((clone $query)->max('duration_ms') ?? 0);
        $slowCount = (clone $query)->where('duration_ms', '>=', $slowThreshold)->count();

        $byChannel = PerformanceMeasurement::query()
            ->selectRaw('channel, COUNT(*) as count, AVG(duration_ms) as avg_duration_ms, MAX(duration_ms) as max_duration_ms')
            ->groupBy('channel')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->channel => [
                    'count' => (int) $row->count,
                    'avg_duration_ms' => round((float) $row->avg_duration_ms, 3),
                    'max_duration_ms' => round((float) $row->max_duration_ms, 3),
                ],
            ])
            ->all();

        $recent = PerformanceMeasurement::query()
            ->orderByDesc('id')
            ->limit($recentLimit)
            ->get()
            ->map(fn (PerformanceMeasurement $row) => [
                'channel' => $row->channel,
                'name' => $row->name,
                'duration_ms' => round((float) $row->duration_ms, 3),
                'status_code' => $row->status_code,
                'metadata' => $row->metadata,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'enabled' => $this->isEnabled(),
            'slow_threshold_ms' => $slowThreshold,
            'summary' => [
                'total_measurements' => $total,
                'avg_duration_ms' => round($avgDuration, 3),
                'max_duration_ms' => round($maxDuration, 3),
                'slow_count' => $slowCount,
            ],
            'by_channel' => $byChannel,
            'recent' => $recent,
        ];
    }

    public function reset(): void
    {
        PerformanceMeasurement::query()->delete();
    }

    private function persist(
        string $channel,
        string $name,
        float $durationMs,
        ?int $statusCode,
        array $metadata,
    ): void {
        if (! config('performance_monitoring.persist', true)) {
            return;
        }

        PerformanceMeasurement::query()->create([
            'channel' => $channel,
            'name' => $name,
            'duration_ms' => round($durationMs, 3),
            'status_code' => $statusCode,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function logIfSlow(string $channel, string $name, float $durationMs, bool $failed = false): void
    {
        $threshold = (float) config('performance_monitoring.slow_threshold_ms', 500);

        if ($durationMs < $threshold && ! $failed) {
            return;
        }

        Log::warning('Performance monitor: slow or failed execution', [
            'channel' => $channel,
            'name' => $name,
            'duration_ms' => round($durationMs, 3),
            'threshold_ms' => $threshold,
            'failed' => $failed,
        ]);
    }
}
