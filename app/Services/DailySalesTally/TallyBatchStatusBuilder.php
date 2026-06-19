<?php

namespace App\Services\DailySalesTally;

use App\Models\DailySalesTallyChunk;
use Illuminate\Support\Collection;

/** Builds lecture-style worker/chunk slot view for the demo UI. */
class TallyBatchStatusBuilder
{
    /**
     * @param  Collection<int, DailySalesTallyChunk>  $completedRows
     * @param  list<array{chunk_index: int, worker_pid: int, worker_terminal: int, started_at: string}>  $runningRows
     * @return array<string, mixed>
     */
    public function buildSlots(
        string $batchId,
        int $expectedChunks,
        Collection $completedRows,
        array $runningRows,
        int $maxConcurrentChunks,
        int $chunkSize,
        int $demoWorkerCount = 4,
    ): array {
        if ($expectedChunks <= 0) {
            return $this->emptyResult($demoWorkerCount);
        }

        $terminalToChunks = [];
        $terminalToPid = [];
        $hasTerminalTracking = false;

        foreach ($completedRows as $row) {
            $terminal = (int) ($row->worker_terminal ?? 0);
            $pid = (int) ($row->worker_pid ?? 0);

            if ($terminal > 0) {
                $hasTerminalTracking = true;
                $terminalToChunks[$terminal][] = (int) $row->chunk_index;
                if ($pid > 0) {
                    $terminalToPid[$terminal] = $pid;
                }
            } elseif ($pid > 0) {
                $hasTerminalTracking = true;
            }
        }

        foreach ($runningRows as $running) {
            $terminal = (int) ($running['worker_terminal'] ?? 0);
            $pid = (int) $running['worker_pid'];

            if ($terminal > 0) {
                $hasTerminalTracking = true;
                if (! isset($terminalToChunks[$terminal]) || ! in_array($running['chunk_index'], $terminalToChunks[$terminal], true)) {
                    $terminalToChunks[$terminal][] = (int) $running['chunk_index'];
                }
                if ($pid > 0) {
                    $terminalToPid[$terminal] = $pid;
                }
            }
        }

        foreach ($terminalToChunks as $terminal => $chunks) {
            sort($terminalToChunks[$terminal]);
        }

        $runningByIndex = collect($runningRows)->keyBy('chunk_index');
        $completedByIndex = $completedRows->keyBy('chunk_index');
        $activeTerminalByChunk = [];

        foreach ($runningRows as $running) {
            $terminal = (int) ($running['worker_terminal'] ?? 0);
            if ($terminal > 0) {
                $activeTerminalByChunk[(int) $running['chunk_index']] = $terminal;
            }
        }

        $chunkSlots = [];

        for ($i = 0; $i < $expectedChunks; $i++) {
            /** @var DailySalesTallyChunk|null $done */
            $done = $completedByIndex->get($i);
            $running = $runningByIndex->get($i);

            if ($done !== null) {
                $terminal = (int) ($done->worker_terminal ?? 0);
                $chunkSlots[] = $this->completedSlot($i, $done, $terminal, $chunkSize);
            } elseif ($running !== null) {
                $terminal = (int) ($running['worker_terminal'] ?? 0);
                $chunkSlots[] = $this->runningSlot($i, $terminal);
            } else {
                $chunkSlots[] = $this->queuedSlot($i);
            }
        }

        $activeWorkerPids = array_values(array_unique(array_map(
            fn (array $r) => (int) $r['worker_pid'],
            $runningRows,
        )));

        $activeTerminals = array_values(array_unique(array_filter(array_map(
            fn (array $r) => (int) ($r['worker_terminal'] ?? 0),
            $runningRows,
        ))));

        $workerProcesses = [];
        $terminalCount = max($demoWorkerCount, 1);
        $queueTerminals = [];
        $doubleDutyWorker = null;

        for ($n = 1; $n <= $terminalCount; $n++) {
            $chunks = $terminalToChunks[$n] ?? [];
            $isBusy = in_array($n, $activeTerminals, true);
            $activeChunkIndex = null;

            if ($isBusy) {
                foreach ($runningRows as $running) {
                    if ((int) ($running['worker_terminal'] ?? 0) === $n) {
                        $activeChunkIndex = (int) $running['chunk_index'];
                        break;
                    }
                }
            }

            $chunkCount = count($chunks);
            if ($chunkCount > 1 && $doubleDutyWorker === null) {
                $doubleDutyWorker = $n;
            }

            $status = $isBusy ? 'busy' : ($chunkCount > 0 ? 'idle' : ($batchId !== '' && $completedRows->count() < $expectedChunks ? 'waiting' : 'idle'));

            $messageEn = $isBusy && $activeChunkIndex !== null
                ? "Worker {$n} is running chunk #".($activeChunkIndex + 1).' now'
                : ($chunkCount > 1
                    ? "Worker {$n} completed chunks ".implode(', ', array_map(fn ($c) => '#'.($c + 1), $chunks)).' — same terminal, two jobs in sequence'
                    : ($chunkCount === 1
                        ? "Worker {$n} completed chunk #".($chunks[0] + 1)
                        : ($batchId !== '' && $completedRows->count() < $expectedChunks
                            ? "Terminal {$n}: waiting to pick a chunk job from the queue"
                            : "Terminal {$n}: idle (no chunk assigned yet)")));

            $messageAr = $isBusy && $activeChunkIndex !== null
                ? "Worker {$n} يعالج الجزء #".($activeChunkIndex + 1).' الآن'
                : ($chunkCount > 1
                    ? "Worker {$n} أنهى أجزاء ".implode(', ', array_map(fn ($c) => '#'.($c + 1), $chunks)).' — terminal واحد، jobان متتابعان'
                    : ($chunkCount === 1
                        ? "Worker {$n} أنهى الجزء #".($chunks[0] + 1)
                        : ($batchId !== ''
                            ? "Terminal {$n}: ينتظر job من الطابور"
                            : "Terminal {$n}: شغّل queue:work في نافذة منفصلة")));

            $terminalRow = [
                'terminal_number' => $n,
                'worker_label' => "queue:work #{$n}",
                'status' => $status,
                'worker_pid' => $terminalToPid[$n] ?? null,
                'active_chunk_index' => $activeChunkIndex,
                'chunks_handled' => $chunks,
                'message_en' => $messageEn,
                'message_ar' => $messageAr,
            ];

            $queueTerminals[] = $terminalRow;

            if ($chunkCount > 0 || $isBusy) {
                $workerProcesses[] = [
                    'worker_number' => $n,
                    'worker_pid' => $terminalToPid[$n] ?? null,
                    'worker_label' => "queue:work #{$n}",
                    'chunks_handled' => $chunks,
                    'chunks_count' => $chunkCount,
                    'active_chunk_index' => $activeChunkIndex,
                    'status' => $isBusy ? 'busy' : 'idle',
                    'message_en' => $messageEn,
                    'message_ar' => $messageAr,
                ];
            }
        }

        $completedChunks = $completedRows->count();
        $activeCount = count($activeTerminals);

        $lectureEn = "Lecture model: each queue:work terminal = one worker thread. "
            ."You run {$terminalCount} terminal(s), batch has {$expectedChunks} chunk(s), "
            ."semaphore allows up to {$maxConcurrentChunks} concurrent chunk jobs. ";

        if ($expectedChunks > $terminalCount) {
            $lectureEn .= "Because {$expectedChunks} chunks > {$terminalCount} workers, the first {$terminalCount} chunks run in parallel; "
                ."when a worker finishes, it picks the next queued chunk (chunk {$expectedChunks}). ";
        }

        if ($doubleDutyWorker !== null) {
            $lectureEn .= "Worker {$doubleDutyWorker} ran twice (sequential jobs on the same process).";
        } elseif ($activeCount > 0) {
            $lectureEn .= "Currently {$activeCount} worker(s) busy — watch the blue cards update.";
        }

        $lectureAr = "نموذج المحاضرة: كل queue:work = worker. {$terminalCount} terminals، {$expectedChunks} chunks. ";

        if ($expectedChunks > $terminalCount) {
            $lectureAr .= "{$expectedChunks} > {$terminalCount}: أول {$terminalCount} chunks بالتوازي، ثم worker يأخذ chunk التالي. ";
        }

        if ($doubleDutyWorker !== null) {
            $lectureAr .= "Worker {$doubleDutyWorker} عمل مرتين على نفس الـ process.";
        }

        $trackingMissing = $completedChunks > 0 && ! $hasTerminalTracking;

        return [
            'chunk_slots' => $chunkSlots,
            'worker_processes' => $workerProcesses,
            'queue_terminals' => $queueTerminals,
            'lecture_note_en' => trim($lectureEn),
            'lecture_note_ar' => trim($lectureAr),
            'active_worker_pids' => $activeWorkerPids,
            'active_worker_count' => $activeCount,
            'distinct_worker_count' => count($workerProcesses),
            'max_concurrent_chunks' => $maxConcurrentChunks,
            'demo_worker_count' => $terminalCount,
            'double_duty_worker_number' => $doubleDutyWorker,
            'worker_tracking_ok' => ! $trackingMissing,
            'worker_tracking_hint_en' => $trackingMissing
                ? 'Worker numbers missing — stop ALL queue:work terminals (Ctrl+C), then start them again so new code records worker_terminal. Re-run Seed + Queue.'
                : null,
            'worker_tracking_hint_ar' => $trackingMissing
                ? 'أرقام الـ workers غير مسجّلة — أوقف كل queue:work (Ctrl+C) ثم شغّلها من جديد، ثم أعد Seed + Queue.'
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(int $demoWorkerCount): array
    {
        return [
            'chunk_slots' => [],
            'worker_processes' => [],
            'queue_terminals' => [],
            'lecture_note_en' => '',
            'lecture_note_ar' => '',
            'active_worker_pids' => [],
            'active_worker_count' => 0,
            'distinct_worker_count' => 0,
            'max_concurrent_chunks' => 0,
            'demo_worker_count' => max($demoWorkerCount, 1),
            'double_duty_worker_number' => null,
            'worker_tracking_ok' => true,
            'worker_tracking_hint_en' => null,
            'worker_tracking_hint_ar' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function completedSlot(int $index, DailySalesTallyChunk $done, int $terminal, int $chunkSize): array
    {
        return [
            'chunk_index' => $index,
            'chunk_label' => 'Chunk '.($index + 1).' (orders '.($index * $chunkSize + 1).'–'.(($index + 1) * $chunkSize).')',
            'status' => 'completed',
            'worker_pid' => $done->worker_pid,
            'worker_number' => $terminal > 0 ? $terminal : null,
            'worker_label' => $terminal > 0 ? "queue:work #{$terminal}" : 'queue:work (restart workers)',
            'order_count' => $done->order_count,
            'total_quantity' => $done->total_quantity,
            'message_en' => $terminal > 0
                ? "Worker {$terminal} completed chunk ".($index + 1)." ({$done->order_count} orders)"
                : "Chunk ".($index + 1)." completed — restart queue:work to see which worker",
            'message_ar' => $terminal > 0
                ? "Worker {$terminal} أنهى الجزء ".($index + 1)." ({$done->order_count} طلب)"
                : "اكتمل الجزء ".($index + 1)." — أعد تشغيل queue:work",
        ];
    }

    /** @return array<string, mixed> */
    private function runningSlot(int $index, int $terminal): array
    {
        return [
            'chunk_index' => $index,
            'chunk_label' => 'Chunk '.($index + 1),
            'status' => 'running',
            'worker_pid' => null,
            'worker_number' => $terminal > 0 ? $terminal : null,
            'worker_label' => $terminal > 0 ? "queue:work #{$terminal}" : 'queue:work',
            'order_count' => null,
            'total_quantity' => null,
            'message_en' => $terminal > 0
                ? "Worker {$terminal} is processing chunk ".($index + 1).' now…'
                : 'Processing chunk '.($index + 1).'…',
            'message_ar' => $terminal > 0
                ? "Worker {$terminal} يعالج الجزء ".($index + 1).'…'
                : 'جاري الجزء '.($index + 1),
        ];
    }

    /** @return array<string, mixed> */
    private function queuedSlot(int $index): array
    {
        return [
            'chunk_index' => $index,
            'chunk_label' => 'Chunk '.($index + 1),
            'status' => 'queued',
            'worker_pid' => null,
            'worker_number' => null,
            'worker_label' => '—',
            'order_count' => null,
            'total_quantity' => null,
            'message_en' => 'Waiting in queue for a free queue:work process',
            'message_ar' => 'في الطابور — ينتظر worker متاح',
        ];
    }
}
