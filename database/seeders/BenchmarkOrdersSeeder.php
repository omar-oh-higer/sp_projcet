<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Seeder;

/** Seeds success orders for Task 10 sales-report benchmark demos. */
class BenchmarkOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()->first();

        if (! $product) {
            return;
        }

        $existing = Order::query()
            ->where('product_id', $product->id)
            ->where('status', 'success')
            ->count();

        if ($existing >= 25) {
            return;
        }

        $toCreate = 25 - $existing;
        $rows = [];

        for ($i = 0; $i < $toCreate; $i++) {
            $rows[] = [
                'product_id' => $product->id,
                'user_id' => null,
                'payment_id' => null,
                'quantity' => 1,
                'status' => 'success',
                'failure_reason' => null,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ];
        }

        Order::query()->insert($rows);
    }
}
