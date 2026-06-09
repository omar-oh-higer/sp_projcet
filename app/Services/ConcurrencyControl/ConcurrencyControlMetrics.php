<?php

namespace App\Services\ConcurrencyControl;

/** Demo counters for Task 7 optimistic vs distributed lock paths. */
class ConcurrencyControlMetrics
{
    public int $optimisticConflicts = 0;

    public int $optimisticSuccesses = 0;

    public int $lockAcquired = 0;

    public int $lockTimeouts = 0;

    public int $distributedSuccesses = 0;

    public function reset(): void
    {
        $this->optimisticConflicts = 0;
        $this->optimisticSuccesses = 0;
        $this->lockAcquired = 0;
        $this->lockTimeouts = 0;
        $this->distributedSuccesses = 0;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'optimistic_conflicts' => $this->optimisticConflicts,
            'optimistic_successes' => $this->optimisticSuccesses,
            'lock_acquired' => $this->lockAcquired,
            'lock_timeouts' => $this->lockTimeouts,
            'distributed_successes' => $this->distributedSuccesses,
        ];
    }
}
