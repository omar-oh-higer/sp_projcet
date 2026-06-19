<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailySalesChunkJob;
use App\Jobs\ProcessDailySalesTallyJob;
use App\Models\DailySalesSummary;
use App\Models\DailySalesTallyChunk;
use App\Models\Product;
use App\Services\DailySalesTally\DailySalesTallyBatchOrchestrator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailySalesBatchTallyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('daily-sales-tally-chunk-semaphore:count');
        config(['daily_sales_tally.demo_chunk_delay_seconds' => 0]);
    }

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

    public function test_tally_queued_returns_concurrent_batch_metadata(): void
    {
        Bus::fake();

        $day = '2026-05-16';
        $this->seedOrdersOnDate($day, 5, 1);

        config(['daily_sales_tally.chunk_size' => 2]);

        $response = $this->postJson('/api/tally-daily-sales-queued', [
            'sale_date' => $day,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['processing_mode' => 'queued_batched_concurrent'])
            ->assertJsonFragment(['sale_date' => $day])
            ->assertJsonFragment(['expected_chunks' => 3])
            ->assertJsonStructure(['batch_id', 'concurrency_note']);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 3
                && $batch->jobs->every(fn ($job) => $job instanceof ProcessDailySalesChunkJob);
        });
    }

    public function test_concurrent_batch_matches_inline_totals(): void
    {
        $day = '2026-05-17';
        $this->seedOrdersOnDate($day, 25, 4);

        $this->postJson('/api/tally-daily-sales-wait', ['sale_date' => $day])->assertOk();
        $inline = DailySalesSummary::query()->whereDate('sale_date', $day)->first();
        $this->assertNotNull($inline);

        DailySalesSummary::query()->whereDate('sale_date', $day)->delete();
        DailySalesTallyChunk::query()->delete();

        config(['daily_sales_tally.chunk_size' => 7]);

        $orchestrator = app(DailySalesTallyBatchOrchestrator::class);
        $result = $orchestrator->start($day);

        $this->assertSame(4, $result['expected_chunks']);
        $this->assertSame(4, DailySalesTallyChunk::query()->where('batch_id', $result['batch_id'])->count());

        $batched = DailySalesSummary::query()->whereDate('sale_date', $day)->first();
        $this->assertNotNull($batched);
        $this->assertSame($inline->successful_order_count, $batched->successful_order_count);
        $this->assertSame((int) $inline->total_quantity, (int) $batched->total_quantity);
        $this->assertSame('queued_batched_concurrent', $batched->processing_mode);
    }

    public function test_empty_day_finalizes_with_zero_totals(): void
    {
        $day = '2026-05-19';

        $result = app(DailySalesTallyBatchOrchestrator::class)->start($day);

        $this->assertSame(0, $result['expected_chunks']);

        $summary = DailySalesSummary::query()->whereDate('sale_date', $day)->first();
        $this->assertNotNull($summary);
        $this->assertSame(0, (int) $summary->successful_order_count);
        $this->assertSame(0, (int) $summary->total_quantity);
        $this->assertSame('queued_batched_concurrent', $summary->processing_mode);
    }

    public function test_legacy_job_delegates_to_concurrent_orchestrator(): void
    {
        Bus::fake();

        $day = '2026-05-20';
        $this->seedOrdersOnDate($day, 3, 1);

        (new ProcessDailySalesTallyJob($day, chunkSize: 2))->handle(app(DailySalesTallyBatchOrchestrator::class));

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
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

    public function test_demo_seed_orders_for_today(): void
    {
        Product::query()->create(['name' => 'Seed Test', 'stock' => 100]);
        $today = now()->toDateString();

        $response = $this->postJson('/api/tally-demo/seed-orders', [
            'sale_date' => $today,
            'count' => 1200,
            'clear_existing' => true,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['sale_date' => $today])
            ->assertJsonFragment(['inserted' => 1200])
            ->assertJsonPath('expected_chunks_if_tally_now', 3);
    }

    public function test_batch_status_returns_chunks_and_summary(): void
    {
        $day = '2026-05-20';
        $this->seedOrdersOnDate($day, 4, 2);
        $batchId = 'test-batch-uuid';

        DailySalesTallyChunk::query()->create([
            'sale_date' => $day,
            'batch_id' => $batchId,
            'chunk_index' => 0,
            'order_count' => 2,
            'total_quantity' => 4,
            'worker_pid' => 1001,
            'worker_terminal' => 1,
        ]);
        DailySalesTallyChunk::query()->create([
            'sale_date' => $day,
            'batch_id' => $batchId,
            'chunk_index' => 1,
            'order_count' => 2,
            'total_quantity' => 4,
            'worker_pid' => 1002,
            'worker_terminal' => 2,
        ]);

        DailySalesSummary::query()->create([
            'sale_date' => $day,
            'successful_order_count' => 4,
            'total_quantity' => 8,
            'processing_mode' => 'queued_batched_concurrent',
            'computed_at' => now(),
        ]);

        $response = $this->getJson('/api/tally-demo/batch-status?sale_date='.$day.'&batch_id='.$batchId.'&expected_chunks=2');

        $response->assertOk()
            ->assertJsonFragment(['completed_chunks' => 2])
            ->assertJsonFragment(['finalize_ready' => true])
            ->assertJsonCount(2, 'chunks')
            ->assertJsonCount(2, 'chunk_slots')
            ->assertJsonCount(4, 'queue_terminals')
            ->assertJsonPath('chunk_slots.0.status', 'completed')
            ->assertJsonPath('chunk_slots.0.worker_label', 'queue:work #1');
    }

    public function test_batch_status_shows_double_duty_worker_when_five_chunks_four_workers(): void
    {
        $day = '2026-05-21';
        $batchId = 'five-chunk-batch';
        config(['daily_sales_tally.demo_worker_count' => 4]);

        for ($i = 0; $i < 5; $i++) {
            DailySalesTallyChunk::query()->create([
                'sale_date' => $day,
                'batch_id' => $batchId,
                'chunk_index' => $i,
                'order_count' => 500,
                'total_quantity' => 1000,
                'worker_pid' => $i < 4 ? 2000 + $i : 2000,
                'worker_terminal' => $i < 4 ? $i + 1 : 1,
            ]);
        }

        $response = $this->getJson('/api/tally-demo/batch-status?sale_date='.$day.'&batch_id='.$batchId.'&expected_chunks=5');

        $response->assertOk()
            ->assertJsonCount(5, 'chunk_slots')
            ->assertJsonCount(4, 'queue_terminals')
            ->assertJsonPath('double_duty_worker_number', 1)
            ->assertJsonPath('worker_tracking_ok', true)
            ->assertJsonPath('chunk_slots.4.worker_label', 'queue:work #1');
    }

    public function test_batch_status_warns_when_worker_terminal_missing(): void
    {
        $day = '2026-05-22';
        $batchId = 'no-terminal-batch';

        DailySalesTallyChunk::query()->create([
            'sale_date' => $day,
            'batch_id' => $batchId,
            'chunk_index' => 0,
            'order_count' => 500,
            'total_quantity' => 1000,
        ]);

        $response = $this->getJson('/api/tally-demo/batch-status?sale_date='.$day.'&batch_id='.$batchId.'&expected_chunks=1');

        $response->assertOk()
            ->assertJsonPath('worker_tracking_ok', false)
            ->assertJsonPath('chunk_slots.0.worker_label', 'queue:work (restart workers)');
    }
}
