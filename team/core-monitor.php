<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\MonitoringException;

class MonitoringService
{
    private const ALERT_THRESHOLD = 0.8; // 80% of limit
    private const METRICS_TTL = 86400; // 24 hours

    public function startOperation(string $operation): string
    {
        $operationId = $this->generateOperationId($operation);
        
        $this->trackOperation($operationId, [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'operation' => $operation,
            'status' => 'started'
        ]);
        
        return $operationId;
    }

    public function track(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $operation();
            
            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_used' => memory_get_usage(true) - $startMemory,
                'status' => 'completed'
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_used' => memory_get_usage(true) - $startMemory,
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function endOperation(string $operationId): void
    {
        $metrics = $this->getMetrics($operationId);
        $metrics['end_time'] = microtime(true);
        $metrics['status'] = 'completed';
        
        $this->storeMetrics($operationId, $metrics);
        
        // Check thresholds
        $this->checkThresholds($metrics);
    }

    private function trackOperation(string $operationId, array $data): void
    {
        $key = "monitor:operation:{$operationId}";
        Cache::put($key, $data, now()->addDay());
    }

    private function recordMetrics(string $operationId, array $metrics): void
    {
        $key = "monitor:metrics:{$operationId}";
        Cache::put($key, $metrics, now()->addDay());
    }

    private function checkThresholds(array $metrics): void
    {
        // Memory usage check
        if (($metrics['memory_used'] ?? 0) > $this->getMemoryLimit() * self::ALERT_THRESHOLD) {
            throw new MonitoringException('Memory usage exceeded threshold');
        }

        // Execution time check
        if (($metrics['execution_time'] ?? 0) > $this->getTimeLimit() * self::ALERT_THRESHOLD) {
            throw new MonitoringException('Execution time exceeded threshold');
        }
    }

    private function getMetrics(string $operationId): array
    {
        return Cache::get("monitor:metrics:{$operationId}", []);
    }

    private function storeMetrics(string $operationId, array $metrics): void
    {
        Cache::put("monitor:metrics:{$operationId}", $metrics, self::METRICS_TTL);
    }

    private function generateOperationId(string $operation): string
    {
        return md5($operation . microtime(true) . uniqid('', true));
    }

    private function getMemoryLimit(): int
    {
        return config('monitoring.memory_limit', 128 * 1024 * 1024); // 128MB default
    }

    private function getTimeLimit(): float
    {
        return config('monitoring.time_limit', 5.0); // 5 seconds default
    }
}
