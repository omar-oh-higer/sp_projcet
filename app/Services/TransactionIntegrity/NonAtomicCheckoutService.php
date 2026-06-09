<?php

namespace App\Services\TransactionIntegrity;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;

/** Task 8 before path: payment, stock, and order as separate auto-committed steps (no ACID). */
class NonAtomicCheckoutService
{
    public function __construct(
        private PaymentGatewaySimulator $paymentGatewaySimulator,
        private CheckoutIntegrityMetrics $checkoutIntegrityMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checkout(
        int $productId,
        int $quantity,
        ?int $userId = null,
        ?string $simulateFailAt = null,
        bool $paymentDeclined = false,
    ): array {
        $product = Product::query()->find($productId);

        if (! $product) {
            return [
                'status' => 'product_not_found',
                'transaction_mode' => 'non_atomic',
            ];
        }

        if ($product->stock < $quantity) {
            return [
                'status' => 'insufficient_stock',
                'transaction_mode' => 'non_atomic',
                'stock' => $product->stock,
            ];
        }

        $amountCents = $product->price_cents * $quantity;

        try {
            $this->paymentGatewaySimulator->assertChargeAllowed($amountCents, $paymentDeclined);
        } catch (PaymentDeclinedException $exception) {
            $this->checkoutIntegrityMetrics->recordNonAtomicFailure();

            return [
                'status' => 'payment_declined',
                'transaction_mode' => 'non_atomic',
                'amount_cents' => $exception->amountCents,
            ];
        }

        $payment = null;

        try {
            $payment = Payment::query()->create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'amount_cents' => $amountCents,
                'status' => 'captured',
                'payment_reference' => $this->paymentGatewaySimulator->generateReference(),
            ]);

            if ($simulateFailAt === 'after_payment') {
                throw new SimulatedCheckoutFailureException('after_payment');
            }

            $product->refresh();

            if ($product->stock < $quantity) {
                throw new SimulatedCheckoutFailureException('after_payment');
            }

            $product->stock = $product->stock - $quantity;
            $product->save();

            if ($simulateFailAt === 'after_stock') {
                throw new SimulatedCheckoutFailureException('after_stock');
            }

            $order = Order::query()->create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'payment_id' => $payment->id,
                'quantity' => $quantity,
                'status' => 'success',
            ]);

            $payment->order_id = $order->id;
            $payment->save();

            $this->checkoutIntegrityMetrics->recordNonAtomicSuccess();

            return [
                'status' => 'success',
                'transaction_mode' => 'non_atomic',
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount_cents' => $amountCents,
                'payment_reference' => $payment->payment_reference,
                'stock' => $product->stock,
                'integrity_violation' => false,
            ];
        } catch (SimulatedCheckoutFailureException $exception) {
            $this->checkoutIntegrityMetrics->recordNonAtomicFailure();
            $this->checkoutIntegrityMetrics->recordIntegrityViolation();

            $product->refresh();

            return [
                'status' => 'simulated_failure',
                'transaction_mode' => 'non_atomic',
                'fail_at' => $exception->failAt,
                'integrity_violation' => true,
                'payment_id' => $payment?->id,
                'amount_cents' => $amountCents,
                'payment_reference' => $payment?->payment_reference,
                'stock' => $product->stock,
            ];
        }
    }
}
