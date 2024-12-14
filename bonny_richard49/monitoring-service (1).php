<?php

namespace App\Core\Protection;

use App\Core\Contracts\MonitoringInterface;
use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Security\SecurityMetrics;

class MonitoringService implements MonitoringInterface
{
    private SecurityMetrics $metrics;
    private AlertService $alerts;
    private string $systemId;

    public function __construct(
        SecurityMetrics $metrics,
        AlertService $alerts,
        string $systemId
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->systemId = $systemId;
    }

    public function startOperation(array $context): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->initializeMonitoring($monitoringId, $context);
        $this->trackResourceUsage($monitoringId);
        $this->monitorSecurityState($monitoringId);
        
        return $monitoringId;
    }

    public function stopOperation(string $monitoringId): void
    {
        $this->finalizeMetrics($monitoringId);
        $this->validateOperationState($monitoringId);
        $this->cleanupMonitoring($monitoringId);
    }

    public function track(string $monitoringId, callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $this->trackOperationStart($monitoringId);
            $result = $operation();
            $this->trackOperationSuccess($monitoringId);
            return $result;
            
        } catch (\Throwable $e) {
            $this->trackOperationFailure($monitoringId, $e);
            throw $e;
            
        } finally {
            $this->recordMetrics($monitoringId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_used' => memory_get_usage(true) - $startMemory,
            ]);
        }
    }

    public function logSecurityViolation(array $context, \Exception $e): void
    {
        $this->metrics->incrementSecurityViolations();
        
        $this->alerts->triggerSecurityAlert([
            'type' => 'security_violation',
            'context' => $context,
            'error' => $e->getMessage(),
            'timestamp' => time(),
            'system_id' => $this->systemId
        ]);

        Log::error('Security violation detected', [
            'context' => $context,
            'exception' => $e,
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    public function logValidationFailure($result, array $context, \Exception $e): void
    {
        $this->metrics->incrementValidationFailures();
        
        Log::warning('Validation failure', [
            'result' => $result,
            'context' => $context,
            'error' => $e->getMessage(),
            'system_id' => $this->systemId,
            'timestamp' => time()
        ]);
    }

    public function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'db_connections' => DB::connection()->select('show status'),
            'cache_stats' => Cache::getMemcached()->getStats(),
            'timestamp' => microtime(true),
            'system_id' => $this->systemId
        ];
    }

    protected function generateMonitoringId(): string
    {
        return uniqid('mon_', true);
    }

    protected function initializeMonitoring(string $monitoringId, array $context): void
    {
        Cache::put("monitoring:$monitoringId", [
            'start_time' => microtime(true),
            'context' => $context,
            'system_id' => $this->systemId,
            'status' => 'initialized'
        ], 3600);
    }

    protected function trackResourceUsage(string $monitoringId): void
    {
        $usage = [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'time' => microtime(true)
        ];
        
        Cache::put("monitoring:$monitoringId:resources", $usage, 3600);
    }

    protected function monitorSecurityState(string $monitoringId): void
    {
        $state = [
            'active_sessions' => $this->getActiveSessions(),
            'failed_attempts' => $this->getFailedAttempts(),
            'security_alerts' => $this->getPendingAlerts(),
            'timestamp' => time()
        ];
        
        Cache::put("monitoring:$monitoringId:security", $state, 3600);
    }

    protected function trackOperationStart(string $monitoringId): void
    {
        Cache::put("monitoring:$monitoringId:status", 'in_progress', 3600);
    }

    protected function trackOperationSuccess(string $monitoringId): void
    {
        Cache::put("monitoring:$monitoringId:status", 'completed', 3600);
        $this->metrics->incrementSuccessfulOperations();
    }

    protected function trackOperationFailure(string $monitoringId, \Throwable $e): void
    {
        Cache::put("monitoring:$monitoringId:status", 'failed', 3600);
        Cache::put("monitoring:$monitoringId:error", [
            'message' => $e->getMessage(),
            'type' => get_class($e),
            'time' => time()
        ], 3600);
        
        $this->metrics->incrementFailedOperations();
    }

    protected function recordMetrics(string $monitoringId, array $metrics): void
    {
        $this->metrics->record($monitoringId, $metrics);
    }

    protected function finalizeMetrics(string $monitoringId): void
    {
        $metrics = Cache::get("monitoring:$monitoringId:resources");
        if ($metrics) {
            $this->metrics->finalize($monitoringId, $metrics);
        }
    }

    protected function validateOperationState(string $monitoringId): void
    {
        $status = Cache::get("monitoring:$monitoringId:status");
        if ($status !== 'completed') {
            Log::warning("Operation did not complete successfully", [
                'monitoring_id' => $monitoringId,
                'final_status' => $status
            ]);
        }
    }

    protected function cleanupMonitoring(string $monitoringId): void
    {
        Cache::delete("monitoring:$monitoringId");
        Cache::delete("monitoring:$monitoringId:resources");
        Cache::delete("monitoring:$monitoringId:security");
        Cache::delete("monitoring:$monitoringId:status");
    }

    private function getActiveSessions(): int
    {
        return Cache::get('active_sessions', 0);
    }

    private function getFailedAttempts(): int
    {
        return Cache::get('failed_attempts', 0);
    }

    private function getPendingAlerts(): array
    {
        return Cache::get('pending_alerts', []);
    }
}
