<?php

namespace App\Core\Notification\Analytics\Streaming;

class StreamProcessor 
{
    private array $processors = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'buffer_size' => 1000,
            'batch_size' => 100,
            'flush_interval' => 60
        ], $config);
    }

    public function registerProcessor(string $name, StreamProcessorInterface $processor): void
    {
        $this->processors[$name] = $processor;
    }

    public function process(string $processor, $data): void
    {
        if (!isset($this->processors[$processor])) {
            throw new \InvalidArgumentException("Unknown processor: {$processor}");
        }

        $startTime = microtime(true);
        try {
            $this->processors[$processor]->process($data);
            $this->recordMetrics($processor, $data, microtime(true) - $startTime, true);
        } catch (\Exception $e) {
            $this->recordMetrics($processor, $data, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function flush(string $processor): void
    {
        if (!isset($this->processors[$processor])) {
            throw new \InvalidArgumentException("Unknown processor: {$processor}");
        }

        $startTime = microtime(true);
        try {
            $this->processors[$processor]->flush();
            $this->recordMetrics($processor . '_flush', null, microtime(true) - $startTime, true);
        } catch (\Exception $e) {
            $this->recordMetrics($processor . '_flush', null, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $processor, $data, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$processor])) {
            $this->metrics[$processor] = [
                'processed' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'average_duration' => 0,
                'bytes_processed' => 0
            ];
        }

        $this->metrics[$processor][$success ? 'processed' : 'failed']++;
        $this->metrics[$processor]['total_duration'] += $duration;
        $this->metrics[$processor]['average_duration'] = 
            $this->metrics[$processor]['total_duration'] / 
            ($this->metrics[$processor]['processed'] + $this->metrics[$processor]['failed']);

        if ($data !== null) {
            $this->metrics[$processor]['bytes_processed'] += $this->calculateSize($data);
        }
    }

    private function calculateSize($data): int
    {
        if (is_string($data)) {
            return strlen($data);
        }
        return strlen(serialize($data));
    }
}

interface StreamProcessorInterface
{
    public function process($data): void;
    public function flush(): void;
}

class BatchStreamProcessor implements StreamProcessorInterface
{
    private array $buffer = [];
    private int $batchSize;
    private callable $processor;

    public function __construct(callable $processor, int $batchSize = 100)
    {
        $this->processor = $processor;
        $this->batchSize = $batchSize;
    }

    public function process($data): void
    {
        $this->buffer[] = $data;

        if (count($this->buffer) >= $this->batchSize) {
            $this->processBatch();
        }
    }

    public function flush(): void
    {
        if (!empty($this->buffer)) {
            $this->processBatch();
        }
    }

    private function processBatch(): void
    {
        $batch = $this->buffer;
        $this->buffer = [];
        ($this->processor)($batch);
    }
}

class WindowedStreamProcessor implements StreamProcessorInterface
{
    private array $windows = [];
    private int $windowSize;
    private int $slideInterval;
    private callable $processor;

    public function __construct(callable $processor, int $windowSize = 60, int $slideInterval = 10)
    {
        $this->processor = $processor;
        $this->windowSize = $windowSize;
        $this->slideInterval = $slideInterval;
    }

    public function process($data): void
    {
        $timestamp = time();
        $windowStart = $this->getWindowStart($timestamp);

        if (!isset($this->windows[$windowStart])) {
            $this->windows[$windowStart] = [];
            $this->cleanOldWindows($timestamp);
        }

        $this->windows[$windowStart][] = $data;
    }

    public function flush(): void
    {
        foreach ($this->windows as $timestamp => $window) {
            ($this->processor)($window, $timestamp);
        }
        $this->windows = [];
    }

    private function getWindowStart(int $timestamp): int
    {
        return floor($timestamp / $this->slideInterval) * $this->slideInterval;
    }

    private function cleanOldWindows(int $currentTimestamp): void
    {
        $cutoff = $currentTimestamp - $this->windowSize;
        
        foreach (array_keys($this->windows) as $timestamp) {
            if ($timestamp < $cutoff) {
                ($this->processor)($this->windows[$timestamp], $timestamp);
                unset($this->windows[$timestamp]);
            }
        }
    }
}

class FilteredStreamProcessor implements StreamProcessorInterface
{
    private array $filters = [];
    private StreamProcessorInterface $processor;

    public function __construct(StreamProcessorInterface $processor)
    {
        $this->processor = $processor;
    }

    public function addFilter(callable $filter): void
    {
        $this->filters[] = $filter;
    }

    public function process($data): void
    {
        if ($this->shouldProcess($data)) {
            $this->processor->process($data);
        }
    }

    public function flush(): void
    {
        $this->processor->flush();
    }

    private function shouldProcess($data): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter($data)) {
                return false;
            }
        }
        return true;
    }
}
