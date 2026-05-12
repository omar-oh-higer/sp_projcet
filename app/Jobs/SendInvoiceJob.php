<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public function __construct(
        public int $orderId,
    ) {}

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