<?php

namespace App\Core\Monitoring;

use App\Core\Security\CoreSecurityService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class MonitoringService implements MonitoringInterface 
{
    private CoreSecurityService $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $audit;
    private array $thresholds;

    public function __construct(
        CoreSecurityService $security,
        MetricsCollector $metrics,
        AlertManager $alerts,
        AuditLogger $audit,
        array $thresholds = []
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->audit = $audit;
        $this->thresholds = $thresholds;
    }

    public function track(Context $context, callable $operation): mixed
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeTracking($operation),
            ['action' => 'monitoring.track', 'context' => $context]
        );
    }

    public function captureMetrics(array $metrics, Context $context): void
    {
        $this->security->executeProtectedOperation(
            fn() => $this->executeMetricsCapture($metrics),
            ['action' => 'monitoring.metrics', 'context' => $context]
        );
    }

    public function checkHealth(Context $context): HealthStatus
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeHealthCheck(),
            ['action' => 'monitoring.health', 'context' => $context]
        );
    }

    private function executeTracking(callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $operation();
            
            $this->recordSuccess(
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordFailure(
                $e,
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory
            );
            throw $e;
        }
    }

    private function executeMetricsCapture(array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            $this->metrics->record($key, $value);
            
            if ($this->isThresholdExceeded($key, $value)) {
                $this->handleThresholdViolation($key, $value);
            }
        }
    }

    private function executeHealthCheck(): HealthStatus
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'memory' => $this->checkMemory(),
        ];

        return new HealthStatus($checks);
    }

    private function recordSuccess(float $duration, int $memory): void
    {
        $this->metrics->timing('operation.duration', $duration);
        $this->metrics->gauge('operation.memory', $memory);
        $this->metrics->increment('operation.success');
        
        if ($duration > $this->thresholds['duration_warning'] ?? 1.0) {
            $this->alerts->warning('Operation duration exceeded threshold', [
                'duration' => $duration,
                'threshold' => $this->thresholds['duration_warning']
            ]);
        }

        if ($memory > $this->thresholds['memory_warning'] ?? 67108864) {
            $this->alerts->warning('Operation memory usage exceeded threshold', [
                'memory' => $memory,
                'threshold' => $this->thresholds['memory_warning']
            ]);
        }
    }

    private function recordFailure(\Throwable $e, float $duration, int $memory): void
    {
        $this->metrics->timing('operation.duration', $duration);
        $this->metrics->gauge('operation.memory', $memory);
        $this->metrics->increment('operation.failure');
        
        $context = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'duration' => $duration,
            'memory' => $memory,
            'trace' => $e->getTraceAsString()
        ];

        $this->audit->logFailure('Operation failed', $context);
        $this->alerts->error('Operation failed', $context);
    }

    private function isThresholdExceeded(string $key, $value): bool
    {
        return isset($this->thresholds[$key]) && $value > $this->thresholds[$key];
    }

    private function handleThresholdViolation(string $key, $value): void
    {
        $this->alerts->warning("Threshold exceeded for $key", [
            'metric' => $key,
            'value' => $value,
            'threshold' => $this->thresholds[$key]
        ]);

        $this->audit->logWarning("Threshold exceeded", [
            'metric' => $key,
            'value' => $value,
            'threshold' => $this->thresholds[$key]
        ]);
    }

    private function checkDatabase(): ComponentStatus
    {
        try {
            $startTime = microtime(true);
            DB::select('SELECT 1');
            $duration = microtime(true) - $startTime;
            
            return new ComponentStatus(
                'healthy',
                $duration < ($this->thresholds['db_warning'] ?? 0.1),
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new ComponentStatus(
                'unhealthy',
                false,
                ['error' => $e->getMessage()]
            );
        }
    }

    private function checkCache(): ComponentStatus
    {
        try {
            $startTime = microtime(true);
            Redis::ping();
            $duration = microtime(true) - $startTime;
            
            return new ComponentStatus(
                'healthy',
                $duration < ($this->thresholds['cache_warning'] ?? 0.05),
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new ComponentStatus(
                'unhealthy',
                false,
                ['error' => $e->getMessage()]
            );
        }
    }

    private function checkStorage(): ComponentStatus
    {
        $path = storage_path();
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        $usage = ($total - $free) / $total * 100;
        
        return new ComponentStatus(
            'healthy',
            $usage < ($this->thresholds['storage_warning'] ?? 90),
            [
                'usage' => $usage,
                'free' => $free,
                'total' => $total
            ]
        );
    }

    private function checkQueue(): ComponentStatus
    {
        try {
            $failed = DB::table('failed_jobs')->count();
            $pending = DB::table('jobs')->count();
            
            return new ComponentStatus(
                'healthy',
                $failed === 0 && $pending < ($this->thresholds['queue_warning'] ?? 100),
                [
                    'failed' => $failed,
                    'pending' => $pending
                ]
            );
        } catch (\Exception $e) {
            return new ComponentStatus(
                'unhealthy',
                false,
                ['error' => $e->getMessage()]
            );
        }
    }

    private function checkMemory(): ComponentStatus
    {
        $usage = memory_get_usage(true);
        $limit = $this->thresholds['memory_critical'] ?? 134217728;
        
        return new ComponentStatus(
            'healthy',
            $usage < $limit,
            [
                'usage' => $usage,
                'limit' => $limit,
                'percentage' => ($usage / $limit) * 100
            ]
        );
    }
}

class MetricsCollector
{
    private array $metrics = [];
    private array $timings = [];
    private array $gauges = [];

    public function record(string $key, $value): void
    {
        $this->metrics[$key] = $value;
    }

    public function timing(string $key, float $duration): void
    {
        $this->timings[$key][] = $duration;
    }

    public function gauge(string $key, $value): void
    {
        $this->gauges[$key] = $value;
    }

    public function increment(string $key, int $value = 1): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = 0;
        }
        $this->metrics[$key] += $value;
    }
}

class AlertManager
{
    private array $handlers;
    private array $levels;

    public function __construct(array $handlers = [], array $levels = [])
    {
        $this->handlers = $handlers;
        $this->levels = $levels;
    }

    public function error(string $message, array $context = []): void
    {
        $this->alert('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->alert('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->alert('info', $message, $context);
    }

    private function alert(string $level, string $message, array $context): void
    {
        if (!isset($this->levels[$level])) {
            return;
        }

        foreach ($this->handlers as $handler) {
            if ($this->levels[$level] >= $handler->getMinLevel()) {
                $handler->handle($level, $message, $context);
            }
        }
    }
}

class AuditLogger
{
    private string $path;
    private array $severity;

    public function __construct(string $path, array $severity = [])
    {
        $this->path = $path;
        $this->severity = $severity;
    }

    public function logFailure(string $message, array $context): void
    {
        $this->log('failure', $message, $context);
    }

    public function logWarning(string $message, array $context): void
    {
        $this->log('warning', $message, $context);
    }

    public function logInfo(string $message, array $context): void
    {
        $this->log('info', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        file_put_contents(
            $this->path,
            json_encode($entry) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

class HealthStatus
{
    private array $components;

    public function __construct(array $components)
    {
        $this->components = $components;
    }

    public function isHealthy(): bool
    {
        return collect($this->components)
            ->every(fn($status) => $status->isHealthy());
    }

    public function getComponents(): array
    {
        return $this->components;
    }
}

class ComponentStatus
{
    private string $status;
    private bool $healthy;
    private array $metrics;

    public function __construct(string $status, bool $healthy, array $metrics = [])
    {
        $this->status = $status;
        $this->healthy = $healthy;
        $this->metrics = $metrics;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
