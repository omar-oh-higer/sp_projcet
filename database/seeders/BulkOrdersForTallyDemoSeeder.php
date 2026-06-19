<?php

namespace Database\Seeders;

use App\Services\DailySalesTally\TallyDemoOrderSeeder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Inserts many successful orders for batch tally demos (Task 4).
 * All rows use the **current calendar day** (server local time) for `created_at`.
 *
 * Run after products exist: php artisan db:seed --class=BulkOrdersForTallyDemoSeeder
 */
class BulkOrdersForTallyDemoSeeder extends Seeder
{
    public const DEFAULT_ORDER_COUNT = 25_000;

    public function run(): void
    {
        $count = (int) config('bulk_orders.seed_count', self::DEFAULT_ORDER_COUNT);
        $saleDate = Carbon::now()->toDateString();

        try {
            $result = app(TallyDemoOrderSeeder::class)->seed($saleDate, $count, false);
        } catch (\RuntimeException $e) {
            $this->command?->warn('BulkOrdersForTallyDemoSeeder: '.$e->getMessage());

            return;
        }

        $this->command?->info(
            "BulkOrdersForTallyDemoSeeder: inserted {$result['inserted']} orders on {$result['sale_date']} "
            ."(total success orders that day: {$result['orders_for_date']})."
        );
    }
}
