<?php

namespace App\Services\TransactionIntegrity;

use Illuminate\Support\Str;

/** Simulates an external payment gateway charge for Task 8 checkout demos. */
class PaymentGatewaySimulator
{
    public function generateReference(): string
    {
        $prefix = (string) config('checkout_integrity.payment_reference_prefix', 'pay_');

        return $prefix.Str::uuid()->toString();
    }

    /**
     * @throws PaymentDeclinedException
     */
    public function assertChargeAllowed(int $amountCents, bool $paymentDeclined): void
    {
        if ($paymentDeclined) {
            throw new PaymentDeclinedException($amountCents);
        }
    }
}
