<?php

namespace App\Services\DailySalesTally;

use App\Jobs\FinalizeDailySalesTallyJob;
use App\Jobs\ProcessDailySalesChunkJob;
use App\Models\Order;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Plans chunk jobs and submits them via Bus::batch (Task 4 concurrent / Task 3 thread pool).
 */
class DailySalesTallyBatchOrchestrator
{
    /**
     * @return array{batch_id: string, expected_chunks: int, sale_date: string}
     */
    public function start(string $saleDate): array
    {
        $chunkJobs = $this->buildChunkJobs($saleDate);
        $expectedChunks = count($chunkJobs);

        if ($expectedChunks === 0) {
            $batchId = (string) Str::uuid();
            FinalizeDailySalesTallyJob::dispatchSync($saleDate, $batchId);

            return [
                'batch_id' => $batchId,
                'expected_chunks' => 0,
                'sale_date' => $saleDate,
            ];
        }

        $batch = Bus::batch($chunkJobs)
            ->name("daily-sales-tally:{$saleDate}")
            ->then(function (Batch $batch) use ($saleDate): void {
                FinalizeDailySalesTallyJob::dispatch($saleDate, $batch->id);
            })
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'expected_chunks' => $expectedChunks,
            'sale_date' => $saleDate,
        ];
    }

    public function expectedChunkCount(string $saleDate): int
    {
        return $this->orderIdChunks($saleDate)->count();
    }

    /**
     * @return list<ProcessDailySalesChunkJob>
     */
    public function buildChunkJobs(string $saleDate): array
    {
        $jobs = [];

        foreach ($this->orderIdChunks($saleDate) as $index => $idChunk) {
            /** @var Collection<int, int> $idChunk */
            $jobs[] = new ProcessDailySalesChunkJob(
                $saleDate,
                $idChunk->values()->all(),
                (int) $index,
            );
        }

        return $jobs;
    }

    /**
     * @return Collection<int, Collection<int, int>>
     */
    private function orderIdChunks(string $saleDate): Collection
    {
        $chunkSize = max((int) config('daily_sales_tally.chunk_size', 500), 1);

        $ids = Order::query()
            ->whereDate('created_at', $saleDate)
            ->where('status', 'success')
            ->orderBy('id')
            ->pluck('id');

        return $ids->chunk($chunkSize);
    }
}
