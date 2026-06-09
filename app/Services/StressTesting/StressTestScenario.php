<?php

namespace App\Services\StressTesting;

/** Task 9: maps scenario keys to checkout stress endpoints. */
class StressTestScenario
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $path,
        public readonly string $transactionMode,
    ) {}

    public static function safeAcid(): self
    {
        return new self(
            key: 'safe',
            label: 'Safe ACID checkout',
            path: '/api/checkout/acid',
            transactionMode: 'acid',
        );
    }

    public static function unsafeNonAtomic(): self
    {
        return new self(
            key: 'unsafe',
            label: 'Unsafe non-atomic checkout',
            path: '/api/checkout/non-atomic',
            transactionMode: 'non_atomic',
        );
    }

    /** @return list<self> */
    public static function forKey(string $key): array
    {
        return match ($key) {
            'safe' => [self::safeAcid()],
            'unsafe' => [self::unsafeNonAtomic()],
            'both' => [self::unsafeNonAtomic(), self::safeAcid()],
            default => throw new \InvalidArgumentException('Invalid scenario. Use safe, unsafe, or both.'),
        };
    }
}
