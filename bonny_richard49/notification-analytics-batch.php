<?php

namespace App\Core\Notification\Analytics\Batch;

class BatchProcessor
{
    private const MAX_BATCH_SIZE = 1000;
    private const MAX_RETRY_ATTEMPTS = 3;
    private array $batches = [];
    private array $results = [];

    public function createBatch(array $items, array $options = []): string
    {
        $batchId = $this->generateBatchId();
        $chunks = array_chunk($items, $options['chunk_size'] ?? self::MAX_BATCH_SIZE);

        $this->batches[$batchId] = [
            'chunks' => $chunks,
            'options' => $options,
            'status' => 'pending',
            'progress' => 0,
            'created_at' => time(),
            'processed' => 0,
            'failed' => 0
        ];

        return $batchId;
    }

    public function processBatch(string $batchId, callable $processor): void
    {
        if (!isset($this->batches[$batchId])) {
            throw new \InvalidArgumentException("Invalid batch ID: {$batchId}");
        }

        $batch = &$this->batches[$batchId];
        $batch['status'] = 'processing';

        foreach ($batch['chunks'] as $index => $chunk) {
            try {
                $result = $processor($chunk);
                $this->handleSuccess($batchId, $index, $result);
            } catch (\Exception $e) {
                $this->handleError($batchId, $index, $e);
            }

            $batch['progress'] = ($index + 1) / count($batch['chunks']) * 100;
        }

        $this->finalizeBatch($batchId);
    }

    public function getBatchStatus(string $batchId): array
    {
        if (!isset($this->batches[$batchId])) {
            throw new \InvalidArgumentException("Invalid batch ID: {$batchId}");
        }

        return [
            'status' => $this->batches[$batchId]['status'],
            'progress' => $this->batches[$batchId]['progress'],
            'processed' => $this->batches[$batchId]['processed'],
            'failed' => $this->batches[$batchId]['failed'],
            'created_at' => $this->batches[$batchId]['created_at']
        ];
    }

    public function getBatchResults(string $batchId): array
    {
        if (!isset($this->results[$batchId])) {
            throw new \InvalidArgumentException("No results found for batch ID: {$batchId}");
        }

        return $this->results[$batchId];
    }

    public function cancelBatch(string $batchId): void
    {
        if (!isset($this->batches[$batchId])) {
            throw new \InvalidArgumentException("Invalid batch ID: {$batchId}");
        }

        $this->batches[$batchId]['status'] = 'cancelled';
        event(new BatchCancelled($batchId));
    }

    private function generateBatchId(): string
    {
        return uniqid('batch_', true);
    }

    private function handleSuccess(string $batchId, int $chunkIndex, array $result): void
    {
        $this->batches[$batchId]['processed']++;
        $this->results[$batchId][$chunkIndex] = [
            'status' => 'success',
            'data' => $result
        ];
    }

    private function handleError(string $batchId, int $chunkIndex, \Exception $error): void
    {
        $this->batches[$batchId]['failed']++;
        $this->results[$batchId][$chunkIndex] = [
            'status' => 'error',
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ];

        event(new BatchChunkFailed($batchId, $chunkIndex, $error));
    }

    private function finalizeBatch(string $batchId): void
    {
        $batch = &$this->batches[$batchId];
        
        $batch['status'] = $batch['failed'] > 0 ? 'completed_with_errors' : 'completed';
        $batch['completed_at'] = time();
        
        event(new BatchCompleted($batchId, [
            'processed' => $batch['processed'],
            'failed' => $batch['failed'],
            'duration' => $batch['completed_at'] - $batch['created_at']
        ]));
    }
}
