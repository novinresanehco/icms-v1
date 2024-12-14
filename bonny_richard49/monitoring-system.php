<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Security\SecurityManager;

class MonitoringSystem implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $audit;
    private HealthCheck $health;
    
    public function track(string $operation, array $context): void
    {
        DB::beginTransaction();
        try {
            $this->recordMetrics($operation, $context);
            $this->checkThresholds($operation, $context);
            $this->logOperation($operation, $context);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($e, $operation, $context);
            throw $e;
        }
    }

    public function monitorEndpoint(string $endpoint, float $responseTime, int $statusCode): void
    {
        $this->metrics->record('endpoint_response', [
            'endpoint' => $endpoint,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'timestamp' => microtime(true)
        ]);

        if ($responseTime > config('monitoring.thresholds.response_time')) {
            $this->alerts->send('response_time_exceeded', [
                'endpoint' => $endpoint,
                'response_time' => $responseTime,
                'threshold' => config('monitoring.thresholds.response_time')
            ]);
        }

        if ($statusCode >= 500) {
            $this->alerts->send('server_error', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode
            ], 'critical');
        }
    }

    public function monitorQuery(string $query, float $executionTime): void
    {
        $this->metrics->record('query_execution', [
            'query' => $this->sanitizeQuery($query),
            'execution_time' => $executionTime,
            'timestamp' => microtime(true)
        ]);

        if ($executionTime > config('monitoring.thresholds.query_time')) {
            $this->alerts->send('slow_query_detected', [
                'query' => $this->sanitizeQuery($query),
                'execution_time' => $executionTime
            ]);
        }
    }

    public function monitorMemory(): void
    {
        $usage = memory_get_peak_usage(true);
        $limit = config('monitoring.thresholds.memory_limit');

        $this->metrics->record('memory_usage', [
            'usage' => $usage,
            'limit' => $limit,
            'timestamp' => microtime(true)
        ]);

        if ($usage > $limit) {
            $this->alerts->send('high_memory_usage', [
                'usage' => $usage,
                'limit' => $limit
            ], 'critical');
        }
    }

    public function checkHealth(): HealthStatus
    {
        return $this->health->perform([
            'database' => fn() => $this->checkDatabaseHealth(),
            'cache' => fn() => $this->checkCacheHealth(),
            'queue' => fn() => $this->checkQueueHealth(),
            'storage' => fn() => $this->checkStorageHealth()
        ]);
    }

    private function checkDatabaseHealth(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Exception $e) {
            $this->alerts->send('database_health_check_failed', [
                'error' => $e->getMessage()
            ], 'critical');
            return false;
        }
    }

    private function checkCacheHealth(): bool
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 1);
            $result = Cache::get($key) === 'test';
            Cache::forget($key);
            return $result;
        } catch (\Exception $e) {
            $this->alerts->send('cache_health_check_failed', [
                'error' => $e->getMessage()
            ], 'critical');
            return false;
        }
    }

    private function checkQueueHealth(): bool
    {
        // Implementation depends on queue driver
        return true;
    }

    private function checkStorageHealth(): bool
    {
        try {
            $testFile = storage_path('health_check.tmp');
            file_put_contents($testFile, 'test');
            $result = file_get_contents($testFile) === 'test';
            unlink($testFile);
            return $result;
        } catch (\Exception $e) {
            $this->alerts->send('storage_health_check_failed', [
                'error' => $e->getMessage()
            ], 'critical');
            return false;
        }
    }

    public function monitorSecurityEvents(): void
    {
        $events = SecurityEvent::where('checked', false)
            ->orderBy('severity', 'desc')
            ->limit(100)
            ->get();

        foreach ($events as $event) {
            $this->processSecurityEvent($event);
        }
    }

    private function processSecurityEvent(SecurityEvent $event): void
    {
        if ($event->severity >= SecurityEvent::CRITICAL) {
            $this->alerts->send('critical_security_event', [
                'event_id' => $event->id,
                'type' => $event->type,
                'details' => $event->details
            ], 'critical');
        }

        $this->audit->logSecurityEvent($event);
        $event->checked = true;
        $event->save();
    }

    private function recordMetrics(string $operation, array $context): void
    {
        $this->metrics->record($operation, [
            'context' => $context,
            'memory' => memory_get_peak_usage(true),
            'time' => microtime(true)
        ]);
    }

    private function checkThresholds(string $operation, array $context): void
    {
        $thresholds = config('monitoring.thresholds.' . $operation, []);
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($context[$metric]) && $context[$metric] > $threshold) {
                $this->alerts->send('threshold_exceeded', [
                    'operation' => $operation,
                    'metric' => $metric,
                    'value' => $context[$metric],
                    'threshold' => $threshold
                ]);
            }
        }
    }

    private function logOperation(string $operation, array $context): void
    {
        $this->audit->log('monitoring', [
            'operation' => $operation,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function handleMonitoringFailure(\Exception $e, string $operation, array $context): void
    {
        Log::error('Monitoring system failure', [
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->send('monitoring_system_failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ], 'critical');
    }

    private function sanitizeQuery(string $query): string
    {
        // Remove sensitive data from query
        return preg_replace('/VALUES\s*\(.*?\)/i', 'VALUES (...)', $query);
    }
}

class MetricsCollector
{
    private array $metrics = [];
    
    public function record(string $type, array $data): void
    {
        $this->metrics[] = [
            'type' => $type,
            'data' => $data,
            'timestamp' => microtime(true)
        ];

        if (count($this->metrics) >= 1000) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->metrics)) {
            return;
        }

        DB::table('system_metrics')->insert($this->metrics);
        $this->metrics = [];
    }
}

class HealthCheck
{
    private array $checks;
    
    public function perform(array $checks): HealthStatus
    {
        $results = [];
        
        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (\Exception $e) {
                $results[$name] = false;
            }
        }

        return new HealthStatus($results);
    }
}
