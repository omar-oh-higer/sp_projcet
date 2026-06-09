<?php

namespace App\Services\TransactionIntegrity;

/** Simulated payment gateway decline for Task 8 demos. */
class PaymentDeclinedException extends \RuntimeException
{
    public function __construct(
        public readonly int $amountCents,
    ) {
        parent::__construct('Payment declined for amount '.$amountCents);
    }
}
