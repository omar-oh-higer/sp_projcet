<?php

namespace App\Services\StressTesting;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/** Demo stress metrics + run log persisted across HTTP requests (Task 9 stats API). */
class StressTestMetrics
{
    private const MAX_RECENT = 30;

    public function reset(): void
    {
        $this->store()->forget($this->cacheKey());
        $this->clearDemoRunLock();
    }

    /** @param  array<string, mixed>  $meta */
    public function markDemoRunStarted(array $meta): void
    {
        $ttl = (int) config('stress_testing.demo_run_lock_ttl_seconds', 300);

        $this->store()->put($this->demoRunLockKey(), [
            ...$meta,
            'started_at' => now()->toIso8601String(),
        ], $ttl);
    }

    public function clearDemoRunLock(): void
    {
        $this->store()->forget($this->demoRunLockKey());
    }

    public function isDemoRunInProgress(): bool
    {
        if (! $this->store()->has($this->demoRunLockKey())) {
            return false;
        }

        $this->maybeClearStaleDemoRunLock();

        return $this->store()->has($this->demoRunLockKey());
    }

    public function maybeClearStaleDemoRunLock(): void
    {
        $lock = $this->demoRunLockSnapshot();

        if ($lock === null) {
            return;
        }

        $startedAt = $lock['started_at'] ?? null;

        if (! is_string($startedAt) || $startedAt === '') {
            return;
        }

        try {
            $started = \Illuminate\Support\Carbon::parse($startedAt);
        } catch (\Throwable) {
            return;
        }

        $staleAfter = (int) config('stress_testing.demo_run_stale_seconds', 45);

        if ($started->diffInSeconds(now()) < $staleAfter) {
            return;
        }

        $runsBefore = (int) ($lock['runs_before'] ?? 0);

        if (count($this->recentRuns()) <= $runsBefore) {
            $this->clearDemoRunLock();
        }
    }

    /** @return array<string, mixed>|null */
    public function demoRunLockSnapshot(): ?array
    {
        /** @var array<string, mixed>|null $lock */
        $lock = $this->store()->get($this->demoRunLockKey());

        return is_array($lock) ? $lock : null;
    }

    /** @param array<string, mixed> $report */
    public function record(array $report): void
    {
        $this->mutate(static function (array &$state) use ($report): void {
            $state['runs_completed']++;
            $state['scenario_reports'][] = $report;

            $state['run_sequence']++;

            $integrity = $report['integrity'] ?? [];

            $state['recent_runs'][] = [
                'run_index' => $state['run_sequence'],
                'scenario' => (string) ($report['scenario'] ?? 'unknown'),
                'scenario_label' => (string) ($report['scenario_label'] ?? ''),
                'concurrent_users' => (int) ($report['concurrent_users'] ?? 0),
                'total_requests' => (int) ($report['total_requests'] ?? 0),
                'success_requests' => (int) ($report['success_requests'] ?? 0),
                'failed_requests' => (int) ($report['failed_requests'] ?? 0),
                'rejected_requests' => (int) ($report['rejected_requests'] ?? 0),
                'connection_errors' => (int) ($report['connection_errors'] ?? 0),
                'data_integrity_pass' => (bool) ($report['data_integrity_pass'] ?? false),
                'orphan_payments_after' => (int) ($integrity['orphan_payments'] ?? 0),
                'pool_duration_ms' => $report['pool_duration_ms'] ?? null,
                'average_response_time_ms' => $report['average_response_time_ms'] ?? null,
                'system_crashed' => (bool) ($report['system_crashed'] ?? false),
                'product_id' => (int) ($report['product_id'] ?? 0),
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($state['recent_runs']) > self::MAX_RECENT) {
                array_shift($state['recent_runs']);
            }
        });
    }

    /** @param array<string, mixed> $combined */
    public function recordCombinedReport(array $combined): void
    {
        $this->mutate(static function (array &$state) use ($combined): void {
            $state['last_combined_report'] = $combined;
        });
    }

    /** @return array<string, mixed>|null */
    public function lastReport(): ?array
    {
        return $this->loadState()['last_combined_report'];
    }

    /** @return list<array<string, mixed>> */
    public function recentRuns(): array
    {
        return $this->loadState()['recent_runs'];
    }

    /** @return list<array<string, mixed>> */
    public function scenarioReports(): array
    {
        return $this->loadState()['scenario_reports'];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $state = $this->loadState();

        return [
            'runs_completed' => $state['runs_completed'],
            'last_report' => $state['last_combined_report'],
            'scenario_reports' => $state['scenario_reports'],
            'last_concurrent_users' => $this->lastConcurrentUsersFromState($state),
        ];
    }

    public function lastConcurrentUsers(): ?int
    {
        return $this->lastConcurrentUsersFromState($this->loadState());
    }

    /** @param  array<string, mixed>  $state */
    private function lastConcurrentUsersFromState(array $state): ?int
    {
        $recent = $state['recent_runs'] ?? [];

        if (! is_array($recent) || $recent === []) {
            return null;
        }

        $last = end($recent);

        if (! is_array($last)) {
            return null;
        }

        $users = (int) ($last['concurrent_users'] ?? 0);

        return $users > 0 ? $users : null;
    }

    /** @param  callable(array<string, mixed>&): void  $callback */
    private function mutate(callable $callback): void
    {
        $state = $this->loadState();
        $callback($state);
        $this->saveState($state);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        /** @var array<string, mixed>|null $state */
        $state = $this->store()->get($this->cacheKey());

        if (! is_array($state)) {
            return $this->emptyState();
        }

        return array_merge($this->emptyState(), $state);
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): void
    {
        $this->store()->forever($this->cacheKey(), $state);
    }

    /** @return array<string, mixed> */
    private function emptyState(): array
    {
        return [
            'runs_completed' => 0,
            'last_combined_report' => null,
            'scenario_reports' => [],
            'recent_runs' => [],
            'run_sequence' => 0,
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('stress_testing.metrics_cache_key', 'stress:demo_metrics');
    }

    private function demoRunLockKey(): string
    {
        return (string) config('stress_testing.demo_run_lock_key', 'stress:demo_run_active');
    }

    private function store(): CacheRepository
    {
        $storeName = config('stress_testing.metrics_store');

        return $storeName
            ? Cache::store((string) $storeName)
            : Cache::store();
    }
}
