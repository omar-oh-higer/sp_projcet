<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Services\TransactionIntegrity\AcidCheckoutService;
use App\Services\TransactionIntegrity\CheckoutIntegrityMetrics;
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

    public function stats(CheckoutIntegrityMetrics $checkoutIntegrityMetrics): JsonResponse
    {
        return response()->json([
            'message' => 'Checkout integrity metrics',
            'currency' => config('checkout_integrity.currency_label', 'USD'),
            'metrics' => $checkoutIntegrityMetrics->snapshot(),
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
