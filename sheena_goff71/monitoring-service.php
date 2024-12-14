<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Interfaces\MonitoringInterface;
use App\Core\Security\SecurityMetrics;

class MonitoringService implements MonitoringInterface 
{
    private SecurityMetrics $metrics;
    private array $activeSessions = [];
    private array $thresholds;

    public function __construct(SecurityMetrics $metrics, array $thresholds)
    {
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
    }

    public function startOperation(array $context): string 
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->activeSessions[$monitoringId] = [
            'start_time' => microtime(true),
            'context' => $context,
            'metrics' => [],
            'status' => 'active'
        ];

        $this->metrics->incrementActiveOperations();
        
        return $monitoringId;
    }

    public function track(string $monitoringId, callable $operation): mixed 
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        try {
            $this->recordPreExecutionMetrics($monitoringId);
            
            $result = $operation();
            
            $this->recordSuccess($monitoringId, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordFailure($monitoringId, $e);
            throw $e;
            
        } finally {
            $this->recordResourceUsage($monitoringId, $startTime, $memoryStart);
        }
    }

    public function stopOperation(string $monitoringId): void 
    {
        if (!isset($this->activeSessions[$monitoringId])) {
            return;
        }

        $session = $this->activeSessions[$monitoringId];
        $duration = microtime(true) - $session['start_time'];

        $this->metrics->recordOperationDuration($duration);
        $this->metrics->decrementActiveOperations();

        $this->finalizeSession($monitoringId, $duration);
    }

    public function captureSystemState(): array 
    {
        return [
            'memory' => $this->captureMemoryMetrics(),
            'cpu' => $this->captureCpuMetrics(),
            'disk' => $this->captureDiskMetrics(),
            'connections' => $this->captureConnectionMetrics(),
            'cache' => $this->captureCacheMetrics(),
            'queue' => $this->captureQueueMetrics()
        ];
    }

    protected function recordPreExecutionMetrics(string $monitoringId): void 
    {
        if (!isset($this->activeSessions[$monitoringId])) {
            return;
        }

        $this->activeSessions[$monitoringId]['metrics']['pre_execution'] = [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'time' => microtime(true)
        ];
    }

    protected function recordSuccess(string $monitoringId, $result): void 
    {
        if (!isset($this->activeSessions[$monitoringId])) {
            return;
        }

        $this->activeSessions[$monitoringId]['metrics']['result'] = [
            'status' => 'success',
            'timestamp' => microtime(true),
            'data_size' => $this->calculateResultSize($result)
        ];

        $this->metrics->incrementSuccessCount();
    }

    protected function recordFailure(string $monitoringId, \Throwable $e): void 
    {
        if (!isset($this->activeSessions[$monitoringId])) {
            return;
        }

        $this->activeSessions[$monitoringId]['metrics']['result'] = [
            'status' => 'failure',
            'timestamp' => microtime(true),
            'error' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]
        ];

        $this->metrics->incrementFailureCount();
    }

    protected function recordResourceUsage(
        string $monitoringId, 
        float $startTime, 
        int $memoryStart
    ): void {
        if (!isset($this->activeSessions[$monitoringId])) {
            return;
        }

        $endTime = microtime(true);
        $memoryEnd = memory_get_usage(true);

        $this->activeSessions[$monitoringId]['metrics']['resources'] = [
            'duration' => $endTime - $startTime,
            'memory_delta' => $memoryEnd - $memoryStart,
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0]
        ];

        $this->checkResourceThresholds($monitoringId);
    }

    protected function finalizeSession(string $monitoringId, float $duration): void 
    {
        $session = $this->activeSessions[$monitoringId];
        
        $this->logSessionMetrics($monitoringId, $session, $duration);
        $this->storeSessionMetrics($monitoringId, $session);
        
        unset($this->activeSessions[$monitoringId]);
    }

    protected function checkResourceThresholds(string $monitoringId): void 
    {
        $metrics = $this->activeSessions[$monitoringId]['metrics'];
        
        foreach ($this->thresholds as $metric => $threshold) {
            if ($this->isThresholdExceeded($metrics, $metric, $threshold)) {
                $this->handleThresholdViolation($monitoringId, $metric, $metrics[$metric]);
            }
        }
    }

    protected function captureMemoryMetrics(): array 
    {
        return [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    protected function captureCpuMetrics(): array 
    {
        return [
            'load' => sys_getloadavg(),
            'process_usage' => $this->getProcessCpuUsage()
        ];
    }

    protected function captureDiskMetrics(): array 
    {
        return [
            'free_space' => disk_free_space(storage_path()),
            'total_space' => disk_total_space(storage_path())
        ];
    }

    protected function captureConnectionMetrics(): array 
    {
        return [
            'db_active' => DB::connection()->select('SELECT COUNT(*) as count FROM information_schema.processlist')[0]->count,
            'redis_active' => Cache::connection()->connection()->getConnection()->isConnected()
        ];
    }

    protected function captureCacheMetrics(): array 
    {
        return [
            'hit_ratio' => $this->calculateCacheHitRatio(),
            'used_memory' => Cache::connection()->info()['used_memory'],
            'total_keys' => Cache::connection()->dbSize()
        ];
    }

    protected function captureQueueMetrics(): array 
    {
        return [
            'pending' => $this->getPendingJobs(),
            'failed' => $this->getFailedJobs(),
            'processing' => $this->getProcessingJobs()
        ];
    }

    private function generateMonitoringId(): string 
    {
        return uniqid('mon_', true);
    }

    private function calculateResultSize($result): int 
    {
        return strlen(serialize($result));
    }

    private function isThresholdExceeded(array $metrics, string $metric, $threshold): bool 
    {
        return isset($metrics[$metric]) && $metrics[$metric] > $threshold;
    }

    private function handleThresholdViolation(string $monitoringId, string $metric, $value): void 
    {
        Log::warning("Threshold exceeded", [
            'monitoring_id' => $monitoringId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric]
        ]);
    }

    private function logSessionMetrics(string $monitoringId, array $session, float $duration): void 
    {
        Log::info("Operation completed", [
            'monitoring_id' => $monitoringId,
            'duration' => $duration,
            'metrics' => $session['metrics']
        ]);
    }

    private function storeSessionMetrics(string $monitoringId, array $session): void 
    {
        Cache::put(
            "monitoring:session:{$monitoringId}",
            $session,
            now()->addDays(7)
        );
    }
}
