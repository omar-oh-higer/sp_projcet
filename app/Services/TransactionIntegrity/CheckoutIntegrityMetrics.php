<?php

namespace App\Services\TransactionIntegrity;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/** Demo counters + checkout log persisted across HTTP requests (Task 8 stats API). */
class CheckoutIntegrityMetrics
{
    private const MAX_RECENT = 30;

    public function recordNonAtomicSuccess(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['non_atomic_successes']++;
        });
    }

    public function recordNonAtomicFailure(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['non_atomic_failures']++;
        });
    }

    public function recordAcidSuccess(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['acid_successes']++;
        });
    }

    public function recordAcidFailure(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['acid_failures']++;
        });
    }

    public function recordIntegrityViolation(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['integrity_violations']++;
        });
    }

    public function reset(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /**
     * @param  'non_atomic'|'acid'  $transactionMode
     */
    public function recordCheckout(
        string $transactionMode,
        string $status,
        bool $integrityViolation,
        int $productId,
        ?string $failAt = null,
        ?int $paymentId = null,
        ?int $orderId = null,
        ?int $stockAfter = null,
    ): void {
        $orphanCount = $this->orphanPaymentCount();

        $this->mutate(static function (array &$state) use (
            $transactionMode,
            $status,
            $integrityViolation,
            $productId,
            $failAt,
            $paymentId,
            $orderId,
            $stockAfter,
            $orphanCount,
        ): void {
            $state['checkout_sequence']++;

            $state['recent_checkouts'][] = [
                'checkout_index' => $state['checkout_sequence'],
                'transaction_mode' => $transactionMode,
                'status' => $status,
                'integrity_violation' => $integrityViolation,
                'fail_at' => $failAt,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'orphan_payments_after' => $orphanCount,
                'stock_after' => $stockAfter,
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($state['recent_checkouts']) > self::MAX_RECENT) {
                array_shift($state['recent_checkouts']);
            }
        });
    }

    /** @param array<string, mixed> $result */
    public function recordCheckoutFromResult(int $productId, array $result): void
    {
        $product = Product::query()->find($productId);

        $this->recordCheckout(
            transactionMode: (string) ($result['transaction_mode'] ?? 'non_atomic'),
            status: (string) ($result['status'] ?? 'unknown'),
            integrityViolation: (bool) ($result['integrity_violation'] ?? false),
            productId: $productId,
            failAt: isset($result['fail_at']) ? (string) $result['fail_at'] : null,
            paymentId: isset($result['payment_id']) ? (int) $result['payment_id'] : null,
            orderId: isset($result['order_id']) ? (int) $result['order_id'] : null,
            stockAfter: $product?->stock ?? ($result['stock'] ?? null),
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentCheckouts(): array
    {
        return $this->loadState()['recent_checkouts'];
    }

    public function orphanPaymentCount(): int
    {
        return Payment::query()
            ->where('status', 'captured')
            ->whereNull('order_id')
            ->count();
    }

    public function ordersWithoutPaymentCount(): int
    {
        return Order::query()
            ->where('status', 'success')
            ->whereNull('payment_id')
            ->count();
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $state = $this->loadState();

        return [
            'non_atomic_successes' => $state['non_atomic_successes'],
            'non_atomic_failures' => $state['non_atomic_failures'],
            'acid_successes' => $state['acid_successes'],
            'acid_failures' => $state['acid_failures'],
            'integrity_violations' => $state['integrity_violations'],
            'orphan_payments' => $this->orphanPaymentCount(),
            'orders_without_payment' => $this->ordersWithoutPaymentCount(),
        ];
    }

    /** @param  callable(array<string, mixed>&): void  $callback */
    private function mutate(callable $callback): void
    {
        $state = $this->loadState();
        $callback($state);
        $this->saveState($state);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        /** @var array<string, mixed>|null $state */
        $state = $this->store()->get($this->cacheKey());

        if (! is_array($state)) {
            return $this->emptyState();
        }

        return array_merge($this->emptyState(), $state);
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): void
    {
        $this->store()->forever($this->cacheKey(), $state);
    }

    /** @return array<string, mixed> */
    private function emptyState(): array
    {
        return [
            'non_atomic_successes' => 0,
            'non_atomic_failures' => 0,
            'acid_successes' => 0,
            'acid_failures' => 0,
            'integrity_violations' => 0,
            'recent_checkouts' => [],
            'checkout_sequence' => 0,
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('checkout_integrity.metrics_cache_key', 'checkout:demo_metrics');
    }

    private function store(): CacheRepository
    {
        $storeName = config('checkout_integrity.metrics_store');

        return $storeName
            ? Cache::store((string) $storeName)
            : Cache::store();
    }
}
