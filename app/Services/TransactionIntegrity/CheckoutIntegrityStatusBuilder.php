<?php

namespace App\Services\TransactionIntegrity;

use App\Models\Product;

/** Builds lecture-style checkout integrity demo view for /demo. */
class CheckoutIntegrityStatusBuilder
{
    public function build(CheckoutIntegrityMetrics $metrics, ?int $exampleProductId = null): array
    {
        $productId = $exampleProductId ?? 1;
        $recent = $metrics->recentCheckouts();
        $snapshot = $metrics->snapshot();

        $nonAtomicViolationsInLog = 0;
        $acidFailuresInLog = 0;
        $maxOrphansInLog = 0;

        foreach ($recent as $row) {
            if (($row['transaction_mode'] ?? '') === 'non_atomic' && ($row['integrity_violation'] ?? false)) {
                $nonAtomicViolationsInLog++;
            }
            if (($row['transaction_mode'] ?? '') === 'acid' && ($row['status'] ?? '') === 'simulated_failure') {
                $acidFailuresInLog++;
            }
            $maxOrphansInLog = max($maxOrphansInLog, (int) ($row['orphan_payments_after'] ?? 0));
        }

        $product = Product::query()->find($productId);

        return [
            'recent_checkouts' => array_map(fn (array $row) => [
                ...$row,
                'message_en' => $this->messageEn($row),
                'message_ar' => $this->messageAr($row),
            ], $recent),
            'db_audit' => [
                'orphan_payments' => $metrics->orphanPaymentCount(),
                'orders_without_payment' => $metrics->ordersWithoutPaymentCount(),
            ],
            'example_product_id' => $productId,
            'product_snapshot' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock,
                'price_cents' => $product->price_cents,
                'version' => $product->version,
            ] : null,
            'scenario_summary' => [
                'non_atomic_violations_in_log' => $nonAtomicViolationsInLog,
                'acid_failures_in_log' => $acidFailuresInLog,
                'integrity_violations_total' => $snapshot['integrity_violations'],
                'non_atomic_failures_total' => $snapshot['non_atomic_failures'],
                'acid_failures_total' => $snapshot['acid_failures'],
                'checkout_count' => count($recent),
                'max_orphan_payments_in_log' => $maxOrphansInLog,
                'current_orphan_payments' => $snapshot['orphan_payments'],
                'initial_demo_stock' => (int) config('checkout_integrity.demo_stock', 10),
                'final_stock' => $product?->stock,
            ],
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function messageEn(array $row): string
    {
        $mode = (string) ($row['transaction_mode'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $orphans = $row['orphan_payments_after'] ?? 0;
        $failAt = $row['fail_at'] ?? null;

        if ($mode === 'non_atomic' && ($row['integrity_violation'] ?? false)) {
            return "Non-atomic partial commit after {$failAt} — integrity violation, orphan payments={$orphans}.";
        }

        if ($mode === 'acid' && $status === 'simulated_failure') {
            return "ACID simulated failure at {$failAt} — full rollback, orphan payments={$orphans}.";
        }

        if ($status === 'success') {
            $stock = $row['stock_after'] ?? '—';

            return "Checkout success ({$mode}) — stock={$stock}.";
        }

        if ($status === 'payment_declined') {
            return "Payment declined ({$mode}) — no side effects persisted.";
        }

        return "Checkout {$mode}: {$status}.";
    }

    /** @param array<string, mixed> $row */
    private function messageAr(array $row): string
    {
        $mode = (string) ($row['transaction_mode'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $orphans = $row['orphan_payments_after'] ?? 0;
        $failAt = $row['fail_at'] ?? null;

        if ($mode === 'non_atomic' && ($row['integrity_violation'] ?? false)) {
            return "غير ذري: commit جزئي بعد {$failAt} — خرق سلامة، مدفوعات يتيمة={$orphans}.";
        }

        if ($mode === 'acid' && $status === 'simulated_failure') {
            return "ACID: فشل محاكى عند {$failAt} — rollback كامل، يتامى={$orphans}.";
        }

        if ($status === 'success') {
            $stock = $row['stock_after'] ?? '—';

            return "نجاح checkout ({$mode}) — مخزون={$stock}.";
        }

        if ($status === 'payment_declined') {
            return "رفض الدفع ({$mode}) — لا آثار جانبية.";
        }

        return "Checkout {$mode}: {$status}.";
    }
}
