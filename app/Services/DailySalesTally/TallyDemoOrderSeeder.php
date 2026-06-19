<?php

namespace App\Services\DailySalesTally;

use App\Models\Product;
use App\Models\DailySalesSummary;
use App\Models\DailySalesTallyChunk;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Inserts successful orders on a given calendar day for Task 4 tally demos. */
class TallyDemoOrderSeeder
{
    /**
     * @return array{sale_date: string, inserted: int, orders_for_date: int}
     */
    public function seed(string $saleDate, int $count, bool $clearExisting = false): array
    {
        $productIds = Product::query()->pluck('id')->all();

        if ($productIds === []) {
            throw new \RuntimeException('No products found. Run php artisan db:seed first.');
        }

        $day = Carbon::parse($saleDate)->startOfDay();

        if ($clearExisting) {
            DB::table('orders')
                ->whereDate('created_at', $day->toDateString())
                ->where('status', 'success')
                ->delete();

            DailySalesTallyChunk::query()->whereDate('sale_date', $day->toDateString())->delete();
            DailySalesSummary::query()->whereDate('sale_date', $day->toDateString())->delete();
        }

        $max = max((int) config('bulk_orders.seed_max', 500_000), 1);
        $count = max(1, min($count, $max));

        $now = now()->format('Y-m-d H:i:s');
        $batch = [];
        $batchSize = 500;

        for ($i = 0; $i < $count; $i++) {
            $createdAt = $day->copy()
                ->addHours(random_int(0, 23))
                ->addMinutes(random_int(0, 59))
                ->addSeconds(random_int(0, 59));

            $batch[] = [
                'product_id' => $productIds[array_rand($productIds)],
                'user_id' => null,
                'quantity' => random_int(1, 5),
                'status' => 'success',
                'failure_reason' => null,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $now,
            ];

            if (count($batch) >= $batchSize) {
                DB::table('orders')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('orders')->insert($batch);
        }

        $ordersForDate = (int) DB::table('orders')
            ->whereDate('created_at', $day->toDateString())
            ->where('status', 'success')
            ->count();

        return [
            'sale_date' => $day->toDateString(),
            'inserted' => $count,
            'orders_for_date' => $ordersForDate,
        ];
    }
}
