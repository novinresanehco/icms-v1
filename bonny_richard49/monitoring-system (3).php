<?php

namespace App\Core\Monitoring;

use App\Core\Interfaces\{
    MonitoringServiceInterface,
    AuditLoggerInterface
};
use App\Core\Exceptions\MonitoringException;
use Illuminate\Support\Facades\{Cache, DB, Log};

class MonitoringService implements MonitoringServiceInterface
{
    private AuditLoggerInterface $auditLogger;
    private array $metrics = [];
    private array $thresholds;
    private array $alerts = [];

    public function __construct(
        AuditLoggerInterface $auditLogger,
        array $config = []
    ) {
        $this->auditLogger = $auditLogger;
        $this->thresholds = $config['thresholds'] ?? [
            'response_time' => 200, // ms
            'memory_usage' => 80,   // percent
            'error_rate' => 1,      // percent
            'cpu_usage' => 70       // percent
        ];
    }

    public function startOperation(string $type, array $context = []): string
    {
        $operationId = $this->generateOperationId();
        
        $this->metrics[$operationId] = [
            'type' => $type,
            'context' => $context,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'status' => 'running'
        ];

        return $operationId;
    }

    public function endOperation(string $operationId, array $result = []): void
    {
        if (!isset($this->metrics[$operationId])) {
            throw new MonitoringException("Unknown operation: $operationId");
        }

        $metrics = &$this->metrics[$operationId];
        $metrics['end_time'] = microtime(true);
        $metrics['memory_end'] = memory_get_usage();
        $metrics['duration'] = $metrics['end_time'] - $metrics['start_time'];
        $metrics['memory_used'] = $metrics['memory_end'] - $metrics['memory_start'];
        $metrics['result'] = $result;
        $metrics['status'] = 'completed';

        $this->checkThresholds($operationId);
    }

    public function recordError(string $operationId, \Throwable $error): void
    {
        if (isset($this->metrics[$operationId])) {
            $metrics = &$this->metrics[$operationId];
            $metrics['status'] = 'failed';
            $metrics['error'] = [
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ];

            $this->auditLogger->logFailure('operation', $error, [
                'operation_id' => $operationId,
                'metrics' => $metrics
            ]);
        }

        $this->updateErrorRate();
    }

    public function getMetrics(string $operationId): array
    {
        if (!isset($this->metrics[$operationId])) {
            throw new MonitoringException("Unknown operation: $operationId");
        }

        return $this->metrics[$operationId];
    }

    public function getSystemMetrics(): array
    {
        $cacheHits = (int)Cache::get('monitoring:cache_hits', 0);
        $cacheMisses = (int)Cache::get('monitoring:cache_misses', 0);
        $totalRequests = (int)Cache::get('monitoring:total_requests', 0);
        $errorCount = (int)Cache::get('monitoring:error_count', 0);

        return [
            'performance' => [
                'response_time' => $this->getAverageResponseTime(),
                'memory_usage' => $this->getMemoryUsage(),
                'cpu_usage' => $this->getCpuUsage()
            ],
            'cache' => [
                'hit_ratio' => $totalRequests > 0 ? 
                    ($cacheHits / $totalRequests) * 100 : 0,
                'hits' => $cacheHits,
                'misses' => $cacheMisses
            ],
            'errors' => [
                'rate' => $totalRequests > 0 ? 
                    ($errorCount / $totalRequests) * 100 : 0,
                'count' => $errorCount
            ],
            'database' => [
                'connections' => DB::getConnections(),
                'slow_queries' => $this->getSlowQueries()
            ],
            'system' => [
                'uptime' => $this->getSystemUptime(),
                'load' => sys_getloadavg()
            ]
        ];
    }

    public function checkHealth(): array
    {
        $metrics = $this->getSystemMetrics();
        $health = ['status' => 'healthy', 'issues' => []];

        // Check response time
        if ($metrics['performance']['response_time'] > $this->thresholds['response_time']) {
            $health['issues'][] = 'High response time';
        }

        // Check memory usage
        if ($metrics['performance']['memory_usage'] > $this->thresholds['memory_usage']) {
            $health['issues'][] = 'High memory usage';
        }

        // Check error rate
        if ($metrics['errors']['rate'] > $this->thresholds['error_rate']) {
            $health['issues'][] = 'High error rate';
        }

        // Check CPU usage
        if ($metrics['performance']['cpu_usage'] > $this->thresholds['cpu_usage']) {
            $health['issues'][] = 'High CPU usage';
        }

        if (!empty($health['issues'])) {
            $health['status'] = count($health['issues']) > 2 ? 'critical' : 'warning';
        }

        return $health;
    }

    protected function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    protected function checkThresholds(string $operationId): void
    {
        $metrics = $this->metrics[$operationId];

        // Check response time
        if ($metrics['duration'] * 1000 > $this->thresholds['response_time']) {
            $this->addAlert($operationId, 'High response time', 'warning');
        }

        // Check memory usage
        $memoryPercent = ($metrics['memory_used'] / ini_get('memory_limit')) * 100;
        if ($memoryPercent > $this->thresholds['memory_usage']) {
            $this->addAlert($operationId, 'High memory usage', 'warning');
        }

        $this->processAlerts();
    }

    protected function addAlert(string $operationId, string $message, string $level): void
    {
        $this->alerts[] = [
            'operation_id' => $operationId,
            'message' => $message,
            'level' => $level,
            'timestamp' => microtime(true)
        ];
    }

    protected function processAlerts(): void
    {
        foreach ($this->alerts as $alert) {
            $this->auditLogger->logSecurity([
                'type' => 'monitoring_alert',
                'severity' => $alert['level'],
                'description' => $alert['message'],
                'source' => [
                    'operation_id' => $alert['operation_id'],
                    'timestamp' => $alert['timestamp']
                ]
            ]);
        }

        $this->alerts = [];
    }

    protected function updateErrorRate(): void
    {
        $errorCount = (int)Cache::get('monitoring:error_count', 0);
        Cache::increment('monitoring:error_count');
        Cache::increment('monitoring:total_requests');
    }

    protected function getAverageResponseTime(): float
    {
        $completed = array_filter($this->metrics, function($m) {
            return $m['status'] === 'completed';
        });

        if (empty($completed)) {
            return 0;
        }

        $total = array_sum(array_column($completed, 'duration'));
        return ($total / count($completed)) * 1000; // Convert to milliseconds
    }

    protected function getMemoryUsage(): float
    {
        $usage = memory_get_usage(true);
        $limit = $this->getMemoryLimit();
        return ($usage / $limit) * 100;
    }

    protected function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
            $value = $matches[1];
            $unit = strtolower($matches[2]);
            switch ($unit) {
                case 'g': $value *= 1024;
                case 'm': $value *= 1024;
                case 'k': $value *= 1024;
            }
            return $value;
        }
        return PHP_INT_MAX;
    }

    protected function getCpuUsage(): float
    {
        $load = sys_getloadavg();
        return $load[0] * 100;
    }

    protected function getSlowQueries(): array
    {
        return DB::select("SELECT * FROM information_schema.processlist 
                          WHERE time > 10 ORDER BY time DESC LIMIT 10");
    }

    protected function getSystemUptime(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return (int)file_get_contents('/proc/uptime');
        }
        return 0;
    }
}
