<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreakerManager
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private $cacheKey = 'circuit-breaker:invoice';
    private $failureWindowSeconds = 60;
    private $failureThreshold = 0.3;
    private $openDurationSeconds = 300;

    public function recordSuccess(): void
    {
        $state = Cache::get("{$this->cacheKey}:state", self::STATE_CLOSED);

        if ($state === self::STATE_HALF_OPEN) {
            Log::info("Circuit breaker: success in HALF_OPEN state, closing circuit");
            Cache::forget("{$this->cacheKey}:state");
            Cache::forget("{$this->cacheKey}:failures");
        }
    }

    public function recordFailure(): void
    {
        $failures = Cache::get("{$this->cacheKey}:failures", []);
        $now = now()->timestamp;

        $failures[] = $now;

        $failures = array_filter($failures, function ($timestamp) use ($now) {
            return ($now - $timestamp) <= $this->failureWindowSeconds;
        });

        Cache::put("{$this->cacheKey}:failures", $failures, $this->failureWindowSeconds + 10);

        $failureRate = count($failures) > 0 ? (count($failures) / 100) : 0;

        if ($failureRate > $this->failureThreshold) {
            Log::warning("Circuit breaker: high failure rate ({$failureRate}), opening circuit");
            Cache::put("{$this->cacheKey}:state", self::STATE_OPEN, $this->openDurationSeconds);
            Cache::put("{$this->cacheKey}:opened_at", $now, $this->openDurationSeconds);
        }
    }

    public function isOpen(): bool
    {
        $state = Cache::get("{$this->cacheKey}:state", self::STATE_CLOSED);

        if ($state === self::STATE_OPEN) {
            $openedAt = Cache::get("{$this->cacheKey}:opened_at", 0);
            $now = now()->timestamp;

            if (($now - $openedAt) > $this->openDurationSeconds) {
                Log::info("Circuit breaker: timeout reached, entering HALF_OPEN state");
                Cache::put("{$this->cacheKey}:state", self::STATE_HALF_OPEN, $this->openDurationSeconds);
                return false;
            }

            return true;
        }

        return false;
    }

    public function getState(): string
    {
        return Cache::get("{$this->cacheKey}:state", self::STATE_CLOSED);
    }
}
