<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Log, Cache, DB};
use App\Core\Services\{ValidationService, NotificationService};
use App\Core\Models\{SecurityEvent, SecurityLog, SystemMetric};
use App\Core\Exceptions\{SecurityException, MonitoringException};

class SecurityMonitor
{
    private ValidationService $validator;
    private NotificationService $notifier;
    private array $securityThresholds;
    
    private const CRITICAL_THRESHOLD = 90;
    private const WARNING_THRESHOLD = 70;
    private const CACHE_TTL = 300;

    public function __construct(
        ValidationService $validator,
        NotificationService $notifier,
        array $securityThresholds
    ) {
        $this->validator = $validator;
        $this->notifier = $notifier;
        $this->securityThresholds = $securityThresholds;
    }

    public function monitorOperation(string $operation, callable $callback)
    {
        $monitorId = $this->startMonitoring($operation);
        
        try {
            $startTime = microtime(true);
            $result = $callback();
            $executionTime = microtime(true) - $startTime;
            
            $this->validateExecution($operation, $executionTime);
            $this->recordSuccess($monitorId, $result, $executionTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleFailure($monitorId, $e);
            throw $e;
        } finally {
            $this->stopMonitoring($monitorId);
        }
    }

    public function trackSecurityEvent(array $data): void
    {
        DB::transaction(function() use ($data) {
            try {
                $validated = $this->validator->validate($data, [
                    'type' => 'required|string',
                    'severity' => 'required|in:low,medium,high,critical',
                    'details' => 'required|array'
                ]);

                SecurityEvent::create($validated);

                if ($this->isThresholdExceeded($validated)) {
                    $this->handleSecurityAlert($validated);
                }
                
                $this->updateSecurityMetrics($validated);
                
            } catch (\Exception $e) {
                Log::critical('Failed to track security event', [
                    'data' => $data,
                    'error' => $e->getMessage()
                ]);
                throw new MonitoringException('Security event tracking failed');
            }
        });
    }

    public function monitorSystemHealth(): array
    {
        $metrics = Cache::remember('system.health', self::CACHE_TTL, function() {
            return [
                'cpu_usage' => $this->getCpuUsage(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage(),
                'connection_count' => $this->getConnectionCount(),
                'error_rate' => $this->getErrorRate(),
                'security_score' => $this->getSecurityScore()
            ];
        });

        $this->validateSystemHealth($metrics);
        return $metrics;
    }

    protected function startMonitoring(string $operation): string
    {
        $monitorId = uniqid('monitor_', true);
        
        SecurityLog::create([
            'monitor_id' => $monitorId,
            'operation' => $operation,
            'start_time' => now(),
            'status' => 'started',
            'context' => [
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]
        ]);

        return $monitorId;
    }

    protected function stopMonitoring(string $monitorId): void
    {
        SecurityLog::where('monitor_id', $monitorId)
            ->update(['end_time' => now(), 'status' => 'completed']);
    }

    protected function validateExecution(string $operation, float $executionTime): void
    {
        $threshold = $this->securityThresholds[$operation] ?? 1.0;
        
        if ($executionTime > $threshold) {
            $this->trackSecurityEvent([
                'type' => 'performance_violation',
                'severity' => 'high',
                'details' => [
                    'operation' => $operation,
                    'execution_time' => $executionTime,
                    'threshold' => $threshold
                ]
            ]);
        }
    }

    protected function recordSuccess(string $monitorId, $result, float $executionTime): void
    {
        SecurityLog::where('monitor_id', $monitorId)->update([
            'status' => 'success',
            'execution_time' => $executionTime,
            'result_hash' => hash('sha256', serialize($result))
        ]);
    }

    protected function handleFailure(string $monitorId, \Exception $e): void
    {
        SecurityLog::where('monitor_id', $monitorId)->update([
            'status' => 'failed',
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]
        ]);

        if ($e instanceof SecurityException) {
            $this->notifier->sendSecurityAlert([
                'monitor_id' => $monitorId,
                'error' => $e->getMessage(),
                'severity' => 'high'
            ]);
        }
    }

    protected function isThresholdExceeded(array $event): bool
    {
        $count = SecurityEvent::where('type', $event['type'])
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $count >= ($this->securityThresholds['event_count'] ?? 10);
    }

    protected function handleSecurityAlert(array $event): void
    {
        $this->notifier->sendSecurityAlert([
            'type' => $event['type'],
            'severity' => $event['severity'],
            'details' => $event['details'],
            'timestamp' => now()
        ]);
    }

    protected function updateSecurityMetrics(array $event): void
    {
        SystemMetric::updateOrCreate(
            ['metric_key' => "security.{$event['type']}"],
            [
                'value' => DB::raw('value + 1'),
                'last_updated' => now()
            ]
        );
    }

    protected function validateSystemHealth(array $metrics): void
    {
        if ($metrics['cpu_usage'] > self::CRITICAL_THRESHOLD ||
            $metrics['memory_usage'] > self::CRITICAL_THRESHOLD ||
            $metrics['disk_usage'] > self::CRITICAL_THRESHOLD) {
                
            $this->notifier->sendSystemAlert([
                'type' => 'resource_critical',
                'metrics' => $metrics,
                'timestamp' => now()
            ]);
        }

        if ($metrics['security_score'] < self::WARNING_THRESHOLD) {
            $this->notifier->sendSecurityAlert([
                'type' => 'security_score_low',
                'score' => $metrics['security_score'],
                'timestamp' => now()
            ]);
        }
    }

    protected function getCpuUsage(): float 
    {
        return sys_getloadavg()[0] * 100;
    }

    protected function getMemoryUsage(): float
    {
        return memory_get_usage(true) / 1024 / 1024;
    }

    protected function getDiskUsage(): float
    {
        return disk_free_space('/') / disk_total_space('/') * 100;
    }

    protected function getConnectionCount(): int
    {
        return DB::table('sessions')->count();
    }

    protected function getErrorRate(): float
    {
        $total = SystemMetric::where('metric_key', 'requests.total')
            ->value('value') ?: 1;
            
        $errors = SystemMetric::where('metric_key', 'requests.errors')
            ->value('value') ?: 0;
            
        return ($errors / $total) * 100;
    }

    protected function getSecurityScore(): float
    {
        $metrics = [
            'encryption' => $this->checkEncryption(),
            'updates' => $this->checkUpdates(),
            'vulnerabilities' => $this->checkVulnerabilities(),
            'access_control' => $this->checkAccessControl()
        ];

        return array_sum($metrics) / count($metrics);
    }
}
