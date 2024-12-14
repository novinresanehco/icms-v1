namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private SecurityMonitor $security;
    private PerformanceMonitor $performance;
    private HealthChecker $health;
    private AlertManager $alerts;
    private AuditLogger $audit;

    public function __construct(
        MetricsCollector $metrics,
        SecurityMonitor $security,
        PerformanceMonitor $performance,
        HealthChecker $health,
        AlertManager $alerts,
        AuditLogger $audit
    ) {
        $this->metrics = $metrics;
        $this->security = $security;
        $this->performance = $performance;
        $this->health = $health;
        $this->alerts = $alerts;
        $this->audit = $audit;
    }

    public function monitorOperation(string $operationId, callable $operation): mixed
    {
        // Initialize monitoring
        $this->startMonitoring($operationId);
        
        try {
            // Execute with full monitoring
            $result = $this->executeWithMonitoring($operationId, $operation);
            
            // Record success metrics
            $this->recordSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Handle and record failure
            $this->handleFailure($operationId, $e);
            throw $e;
        } finally {
            // Always stop monitoring
            $this->stopMonitoring($operationId);
        }
    }

    private function startMonitoring(string $operationId): void
    {
        $this->metrics->initializeOperation($operationId);
        $this->security->startMonitoring($operationId);
        $this->performance->startTracking($operationId);
        $this->health->checkSystem();
    }

    private function executeWithMonitoring(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        
        // Monitor pre-execution state
        $this->recordPreExecutionMetrics($operationId);
        
        // Execute operation
        $result = $operation();
        
        // Monitor post-execution state
        $this->recordPostExecutionMetrics($operationId, microtime(true) - $startTime);
        
        return $result;
    }

    private function recordPreExecutionMetrics(string $operationId): void
    {
        $this->metrics->record($operationId, [
            'memory_initial' => memory_get_usage(true),
            'cpu_initial' => sys_getloadavg()[0],
            'time_start' => microtime(true)
        ]);
    }

    private function recordPostExecutionMetrics(string $operationId, float $duration): void
    {
        $this->metrics->record($operationId, [
            'memory_final' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_final' => sys_getloadavg()[0],
            'duration' => $duration,
            'queries' => DB::getQueryLog()
        ]);

        // Check performance thresholds
        $this->checkPerformanceThresholds($operationId);
    }

    private function checkPerformanceThresholds(string $operationId): void
    {
        $metrics = $this->metrics->getOperationMetrics($operationId);
        
        foreach ($metrics as $metric => $value) {
            if ($this->performance->isThresholdExceeded($metric, $value)) {
                $this->alerts->performanceThresholdExceeded($operationId, $metric, $value);
            }
        }
    }

    private function handleFailure(string $operationId, \Throwable $e): void
    {
        // Record failure metrics
        $this->metrics->recordFailure($operationId, [
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString()
        ]);

        // Log security-related failures
        if ($e instanceof SecurityException) {
            $this->security->logSecurityFailure($operationId, $e);
        }

        // Check system health
        if ($this->health->isSystemCompromised()) {
            $this->alerts->systemCompromised($operationId, $e);
        }

        // Audit failure
        $this->audit->logFailure($operationId, $e);
    }

    private function recordSuccess(string $operationId): void
    {
        $this->metrics->recordSuccess($operationId);
        $this->audit->logSuccess($operationId, $this->metrics->getOperationMetrics($operationId));
    }

    private function stopMonitoring(string $operationId): void
    {
        $this->security->stopMonitoring($operationId);
        $this->performance->stopTracking($operationId);
        $this->metrics->finalizeOperation($operationId);
        
        // Generate monitoring report
        $report = $this->generateMonitoringReport($operationId);
        $this->audit->logMonitoringReport($operationId, $report);
    }

    private function generateMonitoringReport(string $operationId): array
    {
        return [
            'metrics' => $this->metrics->getOperationMetrics($operationId),
            'security' => $this->security->getSecurityReport($operationId),
            'performance' => $this->performance->getPerformanceReport($operationId),
            'health' => $this->health->getSystemStatus(),
            'timestamp' => now()
        ];
    }

    public function getSystemStatus(): SystemStatus
    {
        return new SystemStatus([
            'health' => $this->health->getSystemHealth(),
            'performance' => $this->performance->getCurrentMetrics(),
            'security' => $this->security->getCurrentStatus(),
            'resources' => $this->metrics->getResourceUtilization(),
            'alerts' => $this->alerts->getActiveAlerts()
        ]);
    }
}
