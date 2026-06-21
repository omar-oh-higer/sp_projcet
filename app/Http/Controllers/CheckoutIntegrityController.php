<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\TransactionIntegrity\AcidCheckoutService;
use App\Services\TransactionIntegrity\CheckoutIntegrityMetrics;
use App\Services\TransactionIntegrity\CheckoutIntegrityStatusBuilder;
use App\Services\TransactionIntegrity\NonAtomicCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Task 8: non-atomic vs ACID composite checkout (payment + inventory + order). */
class CheckoutIntegrityController extends Controller
{
    /**
     * Before (Session 7): separate commits — partial failure can orphan payment or stock.
     */
    public function checkoutNonAtomic(
        PurchaseRequest $request,
        NonAtomicCheckoutService $nonAtomicCheckoutService,
    ): JsonResponse {
        return $this->respond(
            $nonAtomicCheckoutService->checkout(
                productId: $request->validated('product_id'),
                quantity: $request->validated('quantity'),
                userId: $request->user()?->id,
                simulateFailAt: $this->normalizeSimulateFailAt($request),
                paymentDeclined: $request->header('X-SIMULATE-PAYMENT-DECLINED') === '1',
            ),
        );
    }

    /**
     * After (Session 7): single DB transaction — all steps succeed or all roll back.
     */
    public function checkoutAcid(
        PurchaseRequest $request,
        AcidCheckoutService $acidCheckoutService,
    ): JsonResponse {
        return $this->respond(
            $acidCheckoutService->checkout(
                productId: $request->validated('product_id'),
                quantity: $request->validated('quantity'),
                userId: $request->user()?->id,
                simulateFailAt: $this->normalizeSimulateFailAt($request),
                paymentDeclined: $request->header('X-SIMULATE-PAYMENT-DECLINED') === '1',
            ),
        );
    }

    public function stats(
        Request $request,
        CheckoutIntegrityMetrics $checkoutIntegrityMetrics,
        CheckoutIntegrityStatusBuilder $checkoutIntegrityStatusBuilder,
    ): JsonResponse {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $view = $checkoutIntegrityStatusBuilder->build($checkoutIntegrityMetrics, $productId);

        return response()->json([
            'message' => 'Checkout integrity metrics',
            'currency' => config('checkout_integrity.currency_label', 'USD'),
            'demo_stock' => (int) config('checkout_integrity.demo_stock', 10),
            'demo_request_delay_ms' => (int) config('checkout_integrity.demo_request_delay_ms', 400),
            'metrics' => $checkoutIntegrityMetrics->snapshot(),
            ...$view,
        ]);
    }

    public function reset(CheckoutIntegrityMetrics $checkoutIntegrityMetrics): JsonResponse
    {
        $checkoutIntegrityMetrics->reset();

        return response()->json([
            'message' => 'Checkout integrity metrics reset',
            'metrics' => $checkoutIntegrityMetrics->snapshot(),
        ]);
    }

    public function demoReset(Request $request, CheckoutIntegrityMetrics $checkoutIntegrityMetrics): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $demoStock = (int) config('checkout_integrity.demo_stock', 10);
        $resetMetrics = $request->boolean('reset_metrics', true);

        $product = Product::query()->find($productId);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        Payment::query()
            ->where('product_id', $productId)
            ->where('status', 'captured')
            ->whereNull('order_id')
            ->delete();

        Order::query()
            ->where('product_id', $productId)
            ->whereNull('payment_id')
            ->delete();

        $product->update([
            'stock' => $demoStock,
            'version' => 0,
        ]);

        if ($resetMetrics) {
            $checkoutIntegrityMetrics->reset();
        }

        return response()->json([
            'message' => $resetMetrics
                ? 'Demo stock restored, orphan payments cleaned, metrics reset.'
                : 'Demo stock restored and orphan payments cleaned (metrics kept for scenario log).',
            'product_id' => $productId,
            'stock' => $demoStock,
            'orphan_payments' => $checkoutIntegrityMetrics->orphanPaymentCount(),
            'metrics_reset' => $resetMetrics,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result): JsonResponse
    {
        $status = match ($result['status']) {
            'product_not_found' => 404,
            'insufficient_stock', 'payment_declined' => 409,
            'simulated_failure' => 500,
            default => 200,
        };

        return response()->json($result, $status);
    }

    private function normalizeSimulateFailAt(Request $request): ?string
    {
        $value = $request->header('X-SIMULATE-FAIL-AT');

        if (! in_array($value, ['after_payment', 'after_stock'], true)) {
            return null;
        }

        return $value;
    }
}
