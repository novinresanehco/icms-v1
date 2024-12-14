<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Log};
use App\Core\Security\SecurityContext;
use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Exceptions\{AuditException, SecurityException};

class AuditLogger implements AuditLoggerInterface
{
    private SecurityContext $context;
    private PerformanceMonitor $monitor;
    private array $config;
    
    private const CRITICAL_EVENTS = [
        'security_breach',
        'data_corruption',
        'system_failure',
        'unauthorized_access'
    ];

    public function logSecurityEvent(string $event, array $data): void
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            $this->validateSecurityContext();
            $logData = $this->prepareSecurityLog($event, $data);
            
            $this->writeSecurityLog($logData);
            $this->alertIfCritical($event, $logData);
            
            DB::commit();
            
            $this->monitor->recordAuditOperation('security', microtime(true) - $startTime);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, 'security', $event);
            throw $e;
        }
    }

    public function logOperationalEvent(string $event, array $data): void
    {
        try {
            DB::beginTransaction();
            
            $logData = $this->prepareOperationalLog($event, $data);
            $this->writeOperationalLog($logData);
            
            if ($this->detectAnomaly($logData)) {
                $this->triggerAnomalyAlert($logData);
            }
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, 'operational', $event);
            throw $e;
        }
    }

    public function logPerformanceMetrics(array $metrics): void
    {
        try {
            $this->validateMetrics($metrics);
            $preparedMetrics = $this->preparePerformanceLog($metrics);
            
            DB::table('performance_logs')->insert($preparedMetrics);
            
            if ($this->detectPerformanceIssue($metrics)) {
                $this->triggerPerformanceAlert($metrics);
            }
            
        } catch (\Throwable $e) {
            $this->handleAuditFailure($e, 'performance', 'metrics');
            throw $e;
        }
    }

    private function validateSecurityContext(): void
    {
        if (!$this->context->isValid()) {
            throw new SecurityException('Invalid security context for audit logging');
        }
    }

    private function prepareSecurityLog(string $event, array $data): array
    {
        return [
            'event' => $event,
            'severity' => $this->determineSeverity($event),
            'user_id' => $this->context->getUserId(),
            'ip_address' => $this->context->getIpAddress(),
            'timestamp' => microtime(true),
            'data' => $this->sanitizeLogData($data),
            'hash' => $this->generateLogHash($event, $data)
        ];
    }

    private function prepareOperationalLog(string $event, array $data): array
    {
        return [
            'event' => $event,
            'component' => $data['component'] ?? 'system',
            'operation' => $data['operation'] ?? 'unknown',
            'timestamp' => microtime(true),
            'data' => $this->sanitizeLogData($data),
            'context' => $this->getOperationalContext()
        ];
    }

    private function preparePerformanceLog(array $metrics): array
    {
        return [
            'metrics' => $metrics,
            'timestamp' => microtime(true),
            'system_load' => sys_getloadavg(),
            'memory_usage' => memory_get_peak_usage(true),
            'context' => $this->getSystemContext()
        ];
    }

    private function writeSecurityLog(array $logData): void
    {
        DB::table('security_logs')->insert($logData);
        
        if ($logData['severity'] === 'critical') {
            Log::critical('Security Event', $logData);
        }
    }

    private function writeOperationalLog(array $logData): void
    {
        DB::table('operational_logs')->insert($logData);
    }

    private function determineSeverity(string $event): string
    {
        return in_array($event, self::CRITICAL_EVENTS) ? 'critical' : 'normal';
    }

    private function sanitizeLogData(array $data): array
    {
        array_walk_recursive($data, function(&$item) {
            if (is_string($item)) {
                $item = strip_tags($item);
                $item = substr($item, 0, 1000);
            }
        });
        
        return $data;
    }

    private function generateLogHash(string $event, array $data): string
    {
        return hash_hmac(
            'sha256',
            $event . json_encode($data),
            $this->config['log_secret']
        );
    }

    private function detectAnomaly(array $logData): bool
    {
        $key = "audit_rate_{$this->context->getUserId()}";
        $threshold = $this->config['anomaly_threshold'] ?? 100;
        
        return DB::table('operational_logs')
            ->where('user_id', $this->context->getUserId())
            ->where('timestamp', '>=', microtime(true) - 3600)
            ->count() > $threshold;
    }

    private function detectPerformanceIssue(array $metrics): bool
    {
        return (
            $metrics['response_time'] > ($this->config['response_threshold'] ?? 200) ||
            $metrics['memory_usage'] > ($this->config['memory_threshold'] ?? 128 * 1024 * 1024) ||
            $metrics['cpu_usage'] > ($this->config['cpu_threshold'] ?? 70)
        );
    }

    private function alertIfCritical(string $event, array $logData): void
    {
        if ($logData['severity'] === 'critical') {
            $this->notifySecurityTeam($event, $logData);
        }
    }

    private function handleAuditFailure(\Throwable $e, string $type, string $event): void
    {
        Log::error("Audit failure: {$type} - {$event}", [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getOperationalContext(): array
    {
        return [
            'server' => gethostname(),
            'process' => getmypid(),
            'memory' => memory_get_usage(true),
            'time' => microtime(true)
        ];
    }

    private function getSystemContext(): array
    {
        return [
            'hostname' => gethostname(),
            'load' => sys_getloadavg(),
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'time' => microtime(true)
        ];
    }
}
