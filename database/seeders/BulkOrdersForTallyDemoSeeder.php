<?php

namespace Database\Seeders;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserts many successful orders for batch tally demos (Task 4).
 * All rows use the **current calendar day** (server local time) for `created_at`
 * (random time within that day) so a tally for today's `sale_date` includes the full seed count.
 *
 * Run after products exist: php artisan db:seed --class=BulkOrdersForTallyDemoSeeder
 */
class BulkOrdersForTallyDemoSeeder extends Seeder
{
    public const DEFAULT_ORDER_COUNT = 25_000;

    /** Bulk-insert demo orders for the current day (see class docblock). */
    public function run(): void
    {
        $productIds = Product::query()->pluck('id')->all();

        if ($productIds === []) {
            $this->command?->warn('BulkOrdersForTallyDemoSeeder: no products found. Seed products first.');

            return;
        }

        $max = (int) config('bulk_orders.seed_max', 500_000);
        $count = (int) config('bulk_orders.seed_count', self::DEFAULT_ORDER_COUNT);
        $count = max(1, min($count, $max));

        $now = Carbon::now();
        $dayStart = $now->copy()->startOfDay();
        $batch = [];
        $batchSize = 500;

        for ($i = 0; $i < $count; $i++) {
            $createdAt = $dayStart->copy()
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
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];

            if (count($batch) >= $batchSize) {
                DB::table('orders')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('orders')->insert($batch);
        }

        $this->command?->info("BulkOrdersForTallyDemoSeeder: inserted {$count} orders on {$dayStart->toDateString()} (all created_at on current day).");
    }
}
