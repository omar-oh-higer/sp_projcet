<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailySalesTallyJob;
use App\Models\DailySalesSummary;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailySalesBatchTallyTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrdersOnDate(string $saleDate, int $count, int $quantityEach = 2): int
    {
        $product = Product::query()->create([
            'name' => 'Tally Test Product',
            'stock' => 10_000,
        ]);

        $at = Carbon::parse($saleDate.' 14:30:00')->format('Y-m-d H:i:s');
        $now = now()->format('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            DB::table('orders')->insert([
                'product_id' => $product->id,
                'user_id' => null,
                'quantity' => $quantityEach,
                'status' => 'success',
                'failure_reason' => null,
                'created_at' => $at,
                'updated_at' => $now,
            ]);
        }

        return $count * $quantityEach;
    }

    public function test_tally_wait_loads_all_rows_inline(): void
    {
        $day = '2026-05-15';
        $expectedQty = $this->seedOrdersOnDate($day, 12, 3);

        $response = $this->postJson('/api/tally-daily-sales-wait', [
            'sale_date' => $day,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['processing_mode' => 'inline_unbatched'])
            ->assertJsonFragment(['successful_order_count' => 12])
            ->assertJsonFragment(['total_quantity' => $expectedQty]);

        $this->assertSame(12, (int) DailySalesSummary::query()->whereDate('sale_date', $day)->value('successful_order_count'));
        $this->assertSame($expectedQty, (int) DailySalesSummary::query()->whereDate('sale_date', $day)->value('total_quantity'));
        $this->assertSame('inline_unbatched', DailySalesSummary::query()->whereDate('sale_date', $day)->value('processing_mode'));
    }

    public function test_tally_queued_dispatches_job_to_queue(): void
    {
        Queue::fake();

        $day = '2026-05-16';
        $this->seedOrdersOnDate($day, 5, 1);

        $response = $this->postJson('/api/tally-daily-sales-queued', [
            'sale_date' => $day,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['processing_mode' => 'queued_batched'])
            ->assertJsonFragment(['sale_date' => $day]);

        Queue::assertPushed(ProcessDailySalesTallyJob::class, 1);
    }

    public function test_batched_job_matches_inline_totals(): void
    {
        $day = '2026-05-17';
        $this->seedOrdersOnDate($day, 25, 4);

        $this->postJson('/api/tally-daily-sales-wait', ['sale_date' => $day])->assertOk();
        $inline = DailySalesSummary::query()->whereDate('sale_date', $day)->first();
        $this->assertNotNull($inline);

        DailySalesSummary::query()->whereDate('sale_date', $day)->delete();

        (new ProcessDailySalesTallyJob($day, chunkSize: 7))->handle();

        $batched = DailySalesSummary::query()->whereDate('sale_date', $day)->first();
        $this->assertNotNull($batched);
        $this->assertSame($inline->successful_order_count, $batched->successful_order_count);
        $this->assertSame((int) $inline->total_quantity, (int) $batched->total_quantity);
        $this->assertSame('queued_batched', $batched->processing_mode);
    }

    public function test_summary_endpoint_returns_stored_row(): void
    {
        $day = '2026-05-18';
        $this->seedOrdersOnDate($day, 3, 2);
        $this->postJson('/api/tally-daily-sales-wait', ['sale_date' => $day])->assertOk();

        $response = $this->getJson('/api/daily-sales-summary?sale_date='.$day);

        $response->assertOk()
            ->assertJsonFragment(['sale_date' => $day])
            ->assertJsonFragment(['successful_order_count' => 3])
            ->assertJsonFragment(['total_quantity' => 6]);
    }
}
