<?php

namespace App\Http\Middleware;

use App\Services\CircuitBreakerManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before-advice style aspect: keeps circuit-breaker policy out of purchase handlers.
 */
class EnsureCircuitBreakerClosed
{
    public function __construct(
        private CircuitBreakerManager $circuitBreaker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->circuitBreaker->isOpen()) {
            return response()->json([
                'message' => 'Service temporarily unavailable (circuit breaker open)',
            ], 503);
        }

        return $next($request);
    }
}
