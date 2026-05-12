<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ReleaseInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public $maxConcurrent = 5;

    public $semaphoreKey = 'invoice-processing-semaphore';

    public function __construct(
        public int $orderId,
    ) {}

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
