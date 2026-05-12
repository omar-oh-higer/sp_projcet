<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Jobs\ReleaseInvoiceJob;
use App\Jobs\SendInvoiceJob;
use App\Models\Product;
use App\Services\CircuitBreakerManager;
use App\Services\StockPurchaseService;
use Illuminate\Http\JsonResponse;

/** Task 1–3 purchase demos (with/without lock, invoice inline vs queued). */
class OrderController extends Controller
{
    /**
     * Task 1 “unsafe” path: read stock, decrement, save — no row lock (race conditions under concurrency).
     * Optional header X-DEMO-DELAY-MS simulates slow reads (capped).
     */
    public function buyWithoutLock(PurchaseRequest $request)
    {
        $payload = $request->validated();
        $delayMs = max((int) $request->header('X-DEMO-DELAY-MS', 0), 0);

        $product = Product::find($payload['product_id']);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        if ($delayMs > 0) {
            usleep(min($delayMs, 5000) * 1000);
        }
    
        if ($product->stock <= 0) {
            return response()->json(['message' => 'Out of stock'], 409);
        }

        if ($product->stock < $payload['quantity']) {
            return response()->json([
                'message' => 'Insufficient stock',
                'stock' => $product->stock,
                'requested_quantity' => $payload['quantity'],
            ], 409);
        }

        $product->stock = $product->stock - $payload['quantity'];
        $product->save();

        return response()->json([
            'message' => 'Purchased WITHOUT lock',
            'stock' => $product->stock,
        ]);
    }

    /**
     * Task 1 safe purchase + Task 3 “after”: transaction + lockForUpdate in StockPurchaseService,
     * then queue invoice via ReleaseInvoiceJob (invoice_mode queued in JSON).
     */
    public function buyWithLock(PurchaseRequest $request, StockPurchaseService $stockPurchaseService, CircuitBreakerManager $circuitBreaker)
    {
        $outcome = $this->lockedPurchaseOutcome($request, $stockPurchaseService);

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        ReleaseInvoiceJob::dispatch($outcome['order_id']);

        $circuitBreaker->recordSuccess();

        return response()->json([
            'message' => 'Purchased WITH lock',
            'stock' => $outcome['stock'],
            'order_id' => $outcome['order_id'],
            'invoice_mode' => 'queued',
        ]);
    }

    /**
     * Task 3 “before”: same locked purchase as buyWithLock, but runs invoice work on this request
     * (handle() only — not dispatch). Optional X-INVOICE-DELAY-MS simulates slow invoice (capped).
     */
    public function buyWithLockWaitForInvoice(PurchaseRequest $request, StockPurchaseService $stockPurchaseService, CircuitBreakerManager $circuitBreaker)
    {
        $outcome = $this->lockedPurchaseOutcome($request, $stockPurchaseService);

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        $invoiceDelayMs = max((int) $request->header('X-INVOICE-DELAY-MS', 0), 0);
        if ($invoiceDelayMs > 0) {
            usleep(min($invoiceDelayMs, 5000) * 1000);
        }

        (new SendInvoiceJob($outcome['order_id']))->handle();

        $circuitBreaker->recordSuccess();

        return response()->json([
            'message' => 'Purchased WITH lock (invoice inline)',
            'stock' => $outcome['stock'],
            'order_id' => $outcome['order_id'],
            'invoice_mode' => 'inline',
        ]);
    }

    /**
     * Shared purchase attempt for locked routes: validates via PurchaseRequest, runs service, returns
     * either an error JsonResponse or success payload [order_id, stock].
     *
     * @return JsonResponse|array{order_id: int, stock: int}
     */
    private function lockedPurchaseOutcome(
        PurchaseRequest $request,
        StockPurchaseService $stockPurchaseService,
    ): JsonResponse|array {
        $payload = $request->validated();
        $result = $stockPurchaseService->purchase(
            productId: $payload['product_id'],
            quantity: $payload['quantity'],
            userId: $request->user()?->id,
        );

        if ($result['status'] === 'product_not_found') {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        if ($result['status'] === 'insufficient_stock') {
            if (($result['stock'] ?? 0) <= 0) {
                return response()->json([
                    'message' => 'Out of stock',
                    'stock' => $result['stock'],
                    'requested_quantity' => $payload['quantity'],
                ], 409);
            }

            return response()->json([
                'message' => 'Insufficient stock',
                'stock' => $result['stock'],
                'requested_quantity' => $payload['quantity'],
            ], 409);
        }

        return [
            'order_id' => $result['order_id'],
            'stock' => $result['stock'],
        ];
    }

    /** Manual check that the queue accepts SendInvoiceJob (dev/demo only). */
    public function testQueue()
    {
        SendInvoiceJob::dispatch(1);

        return 'Job dispatched!';
    }
}