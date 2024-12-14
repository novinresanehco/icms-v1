<?php

namespace App\Core\System;

use App\Core\Interfaces\QueueManagerInterface;
use Illuminate\Support\Facades\{DB, Redis};
use Psr\Log\LoggerInterface;

class QueueManager implements QueueManagerInterface 
{
    private LoggerInterface $logger;
    private array $config;
    private array $metrics;

    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 60;
    private const BATCH_SIZE = 100;
    private const JOB_TIMEOUT = 3600;
    private const METRICS_KEY = 'queue:metrics';

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = config('queue');
        $this->metrics = [];
    }

    public function push(string $queue, array $payload, int $priority = 0): string
    {
        try {
            $jobId = $this->generateJobId();
            
            DB::beginTransaction();
            
            $this->storeJob($jobId, $queue, $payload, $priority);
            $this->pushToQueue($queue, $jobId, $priority);
            
            $this->recordMetric('push', $queue);
            
            DB::commit();
            return $jobId;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueError('push', $e, $queue);
            throw $e;
        }
    }

    public function pushBulk(string $queue, array $payloads, int $priority = 0): array
    {
        try {
            DB::beginTransaction();

            $jobIds = [];
            foreach (array_chunk($payloads, self::BATCH_SIZE) as $chunk) {
                foreach ($chunk as $payload) {
                    $jobId = $this->generateJobId();
                    $this->storeJob($jobId, $queue, $payload, $priority);
                    $jobIds[] = $jobId;
                }
                
                $this->pushBulkToQueue($queue, $jobIds, $priority);
            }

            $this->recordMetric('push_bulk', $queue, count($payloads));

            DB::commit();
            return $jobIds;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueError('push_bulk', $e, $queue);
            throw $e;
        }
    }

    public function later(string $queue, array $payload, int $delay): string
    {
        try {
            $jobId = $this->generateJobId();
            
            DB::beginTransaction();
            
            $this->storeJob($jobId, $queue, $payload);
            $this->scheduleJob($queue, $jobId, $delay);
            
            $this->recordMetric('schedule', $queue);
            
            DB::commit();
            return $jobId;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueError('later', $e, $queue);
            throw $e;
        }
    }

    public function retry(string $jobId): bool
    {
        try {
            DB::beginTransaction();
            
            $job = $this->getJob($jobId);
            if (!$job || $job['attempts'] >= self::RETRY_ATTEMPTS) {
                return false;
            }

            $this->updateJobAttempts($jobId);
            $this->pushToQueue($job['queue'], $jobId, $job['priority']);
            
            $this->recordMetric('retry', $job['queue']);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueError('retry', $e, $jobId);
            return false;
        }
    }

    public function remove(string $jobId): bool
    {
        try {
            DB::beginTransaction();
            
            $job = $this->getJob($jobId);
            if (!$job) {
                return false;
            }

            $this->removeFromQueue($job['queue'], $jobId);
            $this->deleteJob($jobId);
            
            $this->recordMetric('remove', $job['queue']);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueError('remove', $e, $jobId);
            return false;
        }
    }

    protected function storeJob(string $jobId, string $queue, array $payload, int $priority = 0): void
    {
        DB::table('queue_jobs')->insert([
            'id' => $jobId,
            'queue' => $queue,
            'payload' => json_encode($payload),
            'priority' => $priority,
            'attempts' => 0,
            'created_at' => time(),
            'reserved_at' => null
        ]);
    }

    protected function pushToQueue(string $queue, string $jobId, int $priority): void
    {
        Redis::zadd(
            $this->getQueueKey($queue),
            time() + $priority,
            $jobId
        );
    }

    protected function pushBulkToQueue(string $queue, array $jobIds, int $priority): void
    {
        $now = time();
        $members = array_map(
            fn($jobId) => [$now + $priority, $jobId],
            $jobIds
        );
        
        Redis::zadd($this->getQueueKey($queue), ...$members);
    }

    protected function scheduleJob(string $queue, string $jobId, int $delay): void
    {
        Redis::zadd(
            $this->getScheduledQueueKey($queue),
            time() + $delay,
            $jobId
        );
    }

    protected function getJob(string $jobId): ?array
    {
        $job = DB::table('queue_jobs')
            ->where('id', $jobId)
            ->first();

        return $job ? (array)$job : null;
    }

    protected function updateJobAttempts(string $jobId): void
    {
        DB::table('queue_jobs')
            ->where('id', $jobId)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'reserved_at' => time()
            ]);
    }

    protected function removeFromQueue(string $queue, string $jobId): void
    {
        Redis::zrem($this->getQueueKey($queue), $jobId);
        Redis::zrem($this->getScheduledQueueKey($queue), $jobId);
    }

    protected function deleteJob(string $jobId): void
    {
        DB::table('queue_jobs')
            ->where('id', $jobId)
            ->delete();
    }

    protected function generateJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getQueueKey(string $queue): string
    {
        return "queues:{$queue}";
    }

    protected function getScheduledQueueKey(string $queue): string
    {
        return "queues:{$queue}:scheduled";
    }

    protected function recordMetric(string $operation, string $queue, int $count = 1): void
    {
        try {
            $metrics = $this->getMetrics();
            
            $metrics['operations'][$operation] = ($metrics['operations'][$operation] ?? 0) + $count;
            $metrics['queues'][$queue] = ($metrics['queues'][$queue] ?? 0) + $count;
            
            $this->saveMetrics($metrics);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record queue metric', [
                'operation' => $operation,
                'queue' => $queue,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function getMetrics(): array
    {
        try {
            $metrics = Redis::get(self::METRICS_KEY);
            return $metrics ? json_decode($metrics, true) : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get queue metrics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function saveMetrics(array $metrics): void
    {
        try {
            Redis::set(self::METRICS_KEY, json_encode($metrics));
        } catch (\Exception $e) {
            $this->logger->error('Failed to save queue metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function handleQueueError(string $operation, \Exception $e, string $identifier): void
    {
        $this->logger->error('Queue operation failed', [
            'operation' => $operation,
            'identifier' => $identifier,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->shouldRetry($e)) {
            $this->scheduleErrorRetry($operation, $identifier);
        }
    }

    protected function shouldRetry(\Exception $e): bool
    {
        return !($e instanceof \InvalidArgumentException) && 
               !($e instanceof \LogicException);
    }

    protected function scheduleErrorRetry(string $operation, string $identifier): void
    {
        try {
            $this->later('queue.errors', [
                'operation' => $operation,
                'identifier' => $identifier
            ], self::RETRY_DELAY);
        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule error retry', [
                'operation' => $operation,
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
        }
    }
}
