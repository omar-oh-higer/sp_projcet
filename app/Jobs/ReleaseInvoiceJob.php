<?php

namespace App\Jobs;

use App\Jobs\Middleware\MeasureJobPerformance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Task 2: limits how many invoice jobs run at once (cache-based semaphore), then runs
 * SendInvoiceJob for the same order. Dispatched after a successful locked purchase so invoice
 * work stays off the hot path and respects resource caps.
 */
class ReleaseInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public $maxConcurrent = 5;

    public $semaphoreKey = 'invoice-processing-semaphore';

    /** @param int $orderId Order that just succeeded and needs invoice processing */
    public function __construct(
        public int $orderId,
    ) {}

    /** @return array<int, class-string> */
    public function middleware(): array
    {
        return [MeasureJobPerformance::class];
    }

    /**
     * Acquire semaphore slot (or re-queue), run SendInvoiceJob logic for this order, then release slot.
     */
    public function handle()
    {
        $semaphoreKey = $this->semaphoreKey;

        try {
            Log::info("Attempting to acquire semaphore for invoice processing...");

            $acquired = false;
            $attempts = 0;
            $maxAttempts = 30;

            while (!$acquired && $attempts < $maxAttempts) {
                $currentCount = Cache::get("{$semaphoreKey}:count", 0);

                if ($currentCount < $this->maxConcurrent) {
                    Cache::put("{$semaphoreKey}:count", $currentCount + 1, 3600);
                    $acquired = true;
                    Log::info("Semaphore acquired. Current concurrent jobs: " . ($currentCount + 1) . "/{$this->maxConcurrent}");
                } else {
                    $attempts++;
                    if ($attempts < $maxAttempts) {
                        Log::info("Semaphore full ({$currentCount}/{$this->maxConcurrent}), waiting...");
                        sleep(1);
                    }
                }
            }

            if (!$acquired) {
                Log::warning("Failed to acquire semaphore after {$maxAttempts} attempts, retrying...");
                $this->release(5);
                return;
            }

            Log::info("Processing invoice for order {$this->orderId}...");

            $job = new SendInvoiceJob($this->orderId);
            $job->handle();

            Log::info("Invoice processed for order {$this->orderId}");
        } finally {
            $currentCount = Cache::get("{$semaphoreKey}:count", 0);
            if ($currentCount > 0) {
                Cache::put("{$semaphoreKey}:count", $currentCount - 1, 3600);
                Log::info("Semaphore released. Remaining concurrent jobs: " . ($currentCount - 1) . "/{$this->maxConcurrent}");
            }
        }
    }
}
