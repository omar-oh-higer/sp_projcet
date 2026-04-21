<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Jobs\SendInvoiceJob;
use App\Models\Product;
use App\Services\StockPurchaseService;

class OrderController extends Controller
{
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

    public function buyWithLock(PurchaseRequest $request, StockPurchaseService $stockPurchaseService)
    {
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

        return response()->json([
            'message' => 'Purchased WITH lock',
            'stock' => $result['stock'],
            'order_id' => $result['order_id'],
        ]);
    }

    public function testQueue()
    {
        SendInvoiceJob::dispatch();

        return 'Job dispatched!';
    }
}