<?php

namespace App\Services\StressTesting;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;

/** Task 9: pre/post snapshots and invariant checks after concurrent checkout stress. */
class StressTestIntegrityChecker
{
    /** @return array<string, int|null> */
    public function snapshot(int $productId): array
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            return [
                'product_id' => $productId,
                'stock' => null,
                'successful_orders' => 0,
                'captured_payments' => 0,
                'orphan_payments' => 0,
            ];
        }

        return [
            'product_id' => $productId,
            'stock' => $product->stock,
            'successful_orders' => Order::query()
                ->where('product_id', $productId)
                ->where('status', 'success')
                ->count(),
            'captured_payments' => Payment::query()
                ->where('product_id', $productId)
                ->where('status', 'captured')
                ->count(),
            'orphan_payments' => Payment::query()
                ->where('product_id', $productId)
                ->where('status', 'captured')
                ->whereNull('order_id')
                ->count(),
        ];
    }

    /**
     * @param  array<string, int|null>  $before
     * @param  array<string, int|null>  $after
     * @return array<string, mixed>
     */
    public function evaluate(
        array $before,
        array $after,
        int $successRequests,
        int $quantity,
        StressTestScenario $scenario,
    ): array {
        $stockBefore = $before['stock'];
        $stockAfter = $after['stock'];

        if ($stockBefore === null || $stockAfter === null) {
            return [
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'units_sold_expected' => 0,
                'units_sold_actual' => 0,
                'successful_orders' => $after['successful_orders'] ?? 0,
                'captured_payments' => $after['captured_payments'] ?? 0,
                'orphan_payments' => $after['orphan_payments'] ?? 0,
                'data_integrity_pass' => false,
                'integrity_notes' => 'Product not found for integrity check.',
            ];
        }

        $unitsSoldExpected = $successRequests * $quantity;
        $unitsSoldActual = $stockBefore - $stockAfter;
        $orphanPayments = (int) ($after['orphan_payments'] ?? 0);
        $ordersCreated = ((int) ($after['successful_orders'] ?? 0)) - ((int) ($before['successful_orders'] ?? 0));

        $stockMatchesSuccess = $unitsSoldActual === $unitsSoldExpected;
        $stockNotNegative = $stockAfter >= 0;
        $noOrphans = $orphanPayments === 0;

        $pass = $stockMatchesSuccess && $stockNotNegative;

        if ($scenario->key === 'safe') {
            $pass = $pass && $noOrphans && $ordersCreated === $successRequests;
        }

        $notes = [];
        if (! $stockMatchesSuccess) {
            $notes[] = "Stock delta ({$unitsSoldActual}) does not match successful purchases ({$unitsSoldExpected}).";
        }
        if (! $stockNotNegative) {
            $notes[] = 'Stock went negative — overselling detected.';
        }
        if ($orphanPayments > 0) {
            $notes[] = "{$orphanPayments} orphan payment(s) without linked orders.";
        }
        if ($pass) {
            $notes[] = 'All invariants held under concurrent load.';
        }

        return [
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'units_sold_expected' => $unitsSoldExpected,
            'units_sold_actual' => $unitsSoldActual,
            'successful_orders' => $after['successful_orders'] ?? 0,
            'orders_created' => $ordersCreated,
            'captured_payments' => $after['captured_payments'] ?? 0,
            'orphan_payments' => $orphanPayments,
            'data_integrity_pass' => $pass,
            'integrity_notes' => implode(' ', $notes),
        ];
    }
}
