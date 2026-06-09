<?php

namespace App\Services\TransactionIntegrity;

use App\Models\Payment;

/** Demo counters and DB audit helpers for Task 8 checkout integrity. */
class CheckoutIntegrityMetrics
{
    public int $nonAtomicSuccesses = 0;

    public int $nonAtomicFailures = 0;

    public int $acidSuccesses = 0;

    public int $acidFailures = 0;

    public int $integrityViolations = 0;

    public function reset(): void
    {
        $this->nonAtomicSuccesses = 0;
        $this->nonAtomicFailures = 0;
        $this->acidSuccesses = 0;
        $this->acidFailures = 0;
        $this->integrityViolations = 0;
    }

    public function recordNonAtomicSuccess(): void
    {
        $this->nonAtomicSuccesses++;
    }

    public function recordNonAtomicFailure(): void
    {
        $this->nonAtomicFailures++;
    }

    public function recordAcidSuccess(): void
    {
        $this->acidSuccesses++;
    }

    public function recordAcidFailure(): void
    {
        $this->acidFailures++;
    }

    public function recordIntegrityViolation(): void
    {
        $this->integrityViolations++;
    }

    public function orphanPaymentCount(): int
    {
        return Payment::query()
            ->where('status', 'captured')
            ->whereNull('order_id')
            ->count();
    }

    public function ordersWithoutPaymentCount(): int
    {
        return \App\Models\Order::query()
            ->where('status', 'success')
            ->whereNull('payment_id')
            ->count();
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'non_atomic_successes' => $this->nonAtomicSuccesses,
            'non_atomic_failures' => $this->nonAtomicFailures,
            'acid_successes' => $this->acidSuccesses,
            'acid_failures' => $this->acidFailures,
            'integrity_violations' => $this->integrityViolations,
            'orphan_payments' => $this->orphanPaymentCount(),
            'orders_without_payment' => $this->ordersWithoutPaymentCount(),
        ];
    }
}
