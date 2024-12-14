<?php

namespace App\Core\Queue\Workers;

class QueueWorker
{
    private QueueProcessor $processor;
    private WorkerState $state;
    private WorkerMetrics $metrics;
    
    public function __construct(
        QueueProcessor $processor,
        WorkerMetrics $metrics
    ) {
        $this->processor = $processor;
        $this->metrics = $metrics;
        $this->state = new WorkerState();
    }
    
    public function start(string $queueName): void
    {
        $this->state->markAsRunning();
        
        while ($this->state->isRunning()) {
            try {
                $this->processor->process($queueName);
                $this->metrics->recordProcessingCycle();
                
                if ($this->state->shouldPause()) {
                    $this->pause();
                }
            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }
    }
    
    public function stop(): void
    {
        $this->state->markAsStopped();
    }
    
    private function pause(): void
    {
        sleep(1); // Prevent CPU overutilization
    }
    
    private function handleError(\Exception $e): void
    {
        $this->metrics->recordError();
        // Additional error handling logic
    }
}

class WorkerState
{
    private bool $running = false;
    private int $lastProcessedAt;
    
    public function markAsRunning(): void
    {
        $this->running = true;
        $this->lastProcessedAt = time();
    }
    
    public function markAsStopped(): void
    {
        $this->running = false;
    }
    
    public function isRunning(): bool
    {
        return $this->running;
    }
    
    public function shouldPause(): bool
    {
        return (time() - $this->lastProcessedAt) < 1;
    }
}

class WorkerMetrics
{
    private MetricsCollector $collector;
    
    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }
    
    public function recordProcessingCycle(): void
    {
        $this->collector->increment('queue.worker.cycles');
    }
    
    public function recordError(): void
    {
        $this->collector->increment('queue.worker.errors');
    }
}
