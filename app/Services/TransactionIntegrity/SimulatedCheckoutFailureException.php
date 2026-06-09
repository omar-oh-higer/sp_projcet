<?php

namespace App\Services\TransactionIntegrity;

/** Thrown when simulated checkout failure headers trigger a demo rollback scenario. */
class SimulatedCheckoutFailureException extends \RuntimeException
{
    public function __construct(
        public readonly string $failAt,
    ) {
        parent::__construct('Simulated checkout failure at: '.$failAt);
    }
}
