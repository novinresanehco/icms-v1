<?php

namespace App\Core\Audit\Batch;

class BatchProcessor
{
    private array $processors = [];
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        array $processors,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->processors = $processors;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function process(array $items): BatchResult
    {
        $startTime = microtime(true);
        $results = [];
        $errors = [];

        foreach ($items as $item) {
            try {
                $results[] = $this->processItem($item);
            } catch (\Exception $e) {
                $errors[] = [
                    'item' => $item,
                    'error' => $e->getMessage()
                ];
                $this->logger->error('Batch item processing failed', [
                    'error' => $e->getMessage(),
                    'item' => $item
                ]);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->metrics->timing('batch_processing_duration', $duration);

        return new BatchResult($results, $errors);
    }

    private function processItem($item)
    {
        $result = $item;
        foreach ($this->processors as $processor) {
            $result = $processor->process($result);
        }
        return $result;
    }
}

class Batch 
{
    private string $id;
    private array $items = [];
    private int $maxSize;
    private array $metadata = [];

    public function __construct(string $id, int $maxSize = 100)
    {
        $this->id = $id;
        $this->maxSize = $maxSize;
    }

    public function addItem($item): void
    {
        if ($this->isFull()) {
            throw new BatchException('Batch is full');
        }
        
        $this->items[] = $item;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function isFull(): bool
    {
        return count($this->items) >= $this->maxSize;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function size(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class BatchResult
{
    private array $results;
    private array $errors;

    public function __construct(array $results, array $errors = [])
    {
        $this->results = $results;
        $this->errors = $errors;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getSuccessCount(): int
    {
        return count($this->results);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function toArray(): array
    {
        return [
            'success_count' => $this->getSuccessCount(),
            'error_count' => $this->getErrorCount(),
            'results' => $this->results,
            'errors' => $this->errors
        ];
    }
}
