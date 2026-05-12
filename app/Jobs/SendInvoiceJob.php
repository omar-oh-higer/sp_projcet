<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Demo “invoice” step: load the order and log a line (no real email/PDF).
 *
 * Implements ShouldQueue so SendInvoiceJob::dispatch() can go on the queue. For Task 3,
 * buyWithLockWaitForInvoice calls handle() directly on the web thread instead of dispatching.
 */
class SendInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    /** @param int $orderId Primary key of the order to invoice */
    public function __construct(
        public int $orderId,
    ) {}

    /** Run the invoice side effect (log only in this learning project). */
    public function handle()
    {
        $order = Order::query()->find($this->orderId);

        if (!$order) {
            Log::warning("Order {$this->orderId} not found for invoice");
            return;
        }

        Log::info("Invoice sent for order {$this->orderId}: Product #{$order->product_id}, Qty {$order->quantity}");
    }
}