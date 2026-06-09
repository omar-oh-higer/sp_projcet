<?php

namespace App\Services\TransactionIntegrity;

use App\Jobs\ReleaseInvoiceJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\ProductCatalog\ProductCacheInvalidator;
use Illuminate\Support\Facades\DB;

/** Task 8 after path: payment + inventory + order in one DB transaction (ACID). */
class AcidCheckoutService
{
    public function __construct(
        private PaymentGatewaySimulator $paymentGatewaySimulator,
        private ProductCacheInvalidator $productCacheInvalidator,
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
        try {
            $result = DB::transaction(function () use ($productId, $quantity, $userId, $simulateFailAt, $paymentDeclined) {
                $product = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    return [
                        'status' => 'product_not_found',
                        'transaction_mode' => 'acid',
                    ];
                }

                if ($product->stock < $quantity) {
                    return [
                        'status' => 'insufficient_stock',
                        'transaction_mode' => 'acid',
                        'stock' => $product->stock,
                    ];
                }

                $amountCents = $product->price_cents * $quantity;

                $this->paymentGatewaySimulator->assertChargeAllowed($amountCents, $paymentDeclined);

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

                $product->stock = $product->stock - $quantity;
                $product->save();

                $this->productCacheInvalidator->forget($product->id);

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

                return [
                    'status' => 'success',
                    'transaction_mode' => 'acid',
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'amount_cents' => $amountCents,
                    'payment_reference' => $payment->payment_reference,
                    'stock' => $product->stock,
                    'integrity_violation' => false,
                ];
            });
        } catch (SimulatedCheckoutFailureException $exception) {
            $this->checkoutIntegrityMetrics->recordAcidFailure();

            return [
                'status' => 'simulated_failure',
                'transaction_mode' => 'acid',
                'fail_at' => $exception->failAt,
                'integrity_violation' => false,
                'rolled_back' => true,
            ];
        } catch (PaymentDeclinedException $exception) {
            $this->checkoutIntegrityMetrics->recordAcidFailure();

            return [
                'status' => 'payment_declined',
                'transaction_mode' => 'acid',
                'amount_cents' => $exception->amountCents,
                'rolled_back' => true,
            ];
        }

        if (($result['status'] ?? null) === 'success') {
            $this->checkoutIntegrityMetrics->recordAcidSuccess();
            ReleaseInvoiceJob::dispatch($result['order_id'])->afterCommit();
        } elseif (($result['status'] ?? null) === 'insufficient_stock') {
            $this->checkoutIntegrityMetrics->recordAcidFailure();
        }

        return $result;
    }
}
