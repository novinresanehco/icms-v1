<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Cache, Queue, Log, Redis};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{CacheException, QueueException};
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Contracts\Cache\LockProvider;

class SystemOptimizationService
{
    protected SecurityManager $security;
    protected array $config;
    protected LockProvider $lockProvider;
    protected array $metrics = [];

    public function __construct(
        SecurityManager $security,
        LockProvider $lockProvider
    ) {
        $this->security = $security;
        $this->lockProvider = $lockProvider;
        $this->config = config('system.optimization');
        $this->initializeMetrics();
    }

    public function cacheOperation(string $key, $value, array $tags = [], int $ttl = null): void
    {
        $context = $this->createSecurityContext('cache', compact('key', 'tags'));
        
        try {
            $this->security->validateOperation($context);
            
            $ttl = $ttl ?? $this->config['cache_ttl'];
            
            if (!empty($tags)) {
                Cache::tags($tags)->put($key, $value, $ttl);
            } else {
                Cache::put($key, $value, $ttl);
            }
            
            $this->incrementMetric('cache_writes');
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new CacheException('Cache operation failed: ' . $e->getMessage());
        }
    }

    public function getCachedData(string $key, array $tags = [], callable $callback = null)
    {
        $context = $this->createSecurityContext('cache_read', compact('key', 'tags'));
        
        try {
            $this->security->validateOperation($context);
            
            if (!empty($tags)) {
                $value = Cache::tags($tags)->get($key);
            } else {
                $value = Cache::get($key);
            }
            
            if ($value === null && $callback !== null) {
                $value = $callback();
                $this->cacheOperation($key, $value, $tags);
            }
            
            $this->incrementMetric('cache_reads');
            
            return $value;
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new CacheException('Cache read failed: ' . $e->getMessage());
        }
    }

    public function queueJob(string $job, array $data = [], string $queue = null): string
    {
        $context = $this->createSecurityContext('queue', compact('job', 'queue'));
        
        try {
            $this->security->validateOperation($context);
            
            $jobId = $this->generateJobId();
            $data['job_id'] = $jobId;
            
            $queueName = $queue ?? $this->config['default_queue'];
            
            Queue::on($queueName)->push($job, $data);
            
            $this->incrementMetric('queued_jobs');
            
            return $jobId;
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new QueueException('Queue operation failed: ' . $e->getMessage());
        }
    }

    public function executeWithLock(string $key, callable $callback, int $timeout = 10): mixed
    {
        $lock = $this->lockProvider->lock($key, $timeout);
        
        try {
            if (!$lock->get()) {
                throw new CacheException('Failed to acquire lock: ' . $key);
            }
            
            return $callback();
            
        } finally {
            optional($lock)->release();
        }
    }

    public function invalidateCache(array $tags = []): void
    {
        $context = $this->createSecurityContext('cache_invalidate', compact('tags'));
        
        try {
            $this->security->validateOperation($context);
            
            if (!empty($tags)) {
                Cache::tags($tags)->flush();
            } else {
                Cache::flush();
            }
            
            $this->incrementMetric('cache_invalidations');
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new CacheException('Cache invalidation failed: ' . $e->getMessage());
        }
    }

    public function monitorJobStatus(string $jobId): array
    {
        try {
            $status = Redis::hgetall("job:{$jobId}");
            
            if (empty($status)) {
                throw new QueueException("Job not found: {$jobId}");
            }
            
            return [
                'id' => $jobId,
                'status' => $status['status'] ?? 'unknown',
                'progress' => $status['progress'] ?? 0,
                'result' => $status['result'] ?? null,
                'error' => $status['error'] ?? null,
                'created_at' => $status['created_at'] ?? null,
                'updated_at' => $status['updated_at'] ?? null
            ];
            
        } catch (\Exception $e) {
            throw new QueueException('Job status check failed: ' . $e->getMessage());
        }
    }

    public function getMetrics(): array
    {
        return [
            'metrics' => $this->metrics,
            'cache_stats' => $this->getCacheStats(),
            'queue_stats' => $this->getQueueStats()
        ];
    }

    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'cache_reads' => 0,
            'cache_writes' => 0,
            'cache_invalidations' => 0,
            'queued_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0
        ];
    }

    protected function incrementMetric(string $metric): void
    {
        if (isset($this->metrics[$metric])) {
            $this->metrics[$metric]++;
        }
    }

    protected function getCacheStats(): array
    {
        return [
            'size' => Cache::size(),
            'hit_ratio' => $this->calculateHitRatio(),
            'memory_usage' => $this->getMemoryUsage()
        ];
    }

    protected function getQueueStats(): array
    {
        $stats = [];
        
        foreach ($this->config['queues'] as $queue) {
            $stats[$queue] = [
                'size' => Queue::size($queue),
                'failed' => $this->getFailedCount($queue)
            ];
        }
        
        return $stats;
    }

    protected function calculateHitRatio(): float
    {
        $hits = $this->metrics['cache_reads'];
        $total = $hits + $this->metrics['cache_writes'];
        
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }

    protected function getMemoryUsage(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true)
        ];
    }

    protected function getFailedCount(string $queue): int
    {
        return Redis::zcard("failed:{$queue}");
    }

    protected function generateJobId(): string
    {
        return sprintf(
            '%s-%s-%s',
            time(),
            uniqid(),
            substr(md5(random_bytes(16)), 0, 8)
        );
    }

    protected function createSecurityContext(string $operation, array $data = []): array
    {
        return [
            'operation' => $operation,
            'service' => self::class,
            'data' => $data,
            'timestamp' => now(),
            'user_id' => auth()->id()
        ];
    }

    protected function handleException(\Exception $e, array $context): void
    {
        Log::error('System optimization operation failed', [
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
