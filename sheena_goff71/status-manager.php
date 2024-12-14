```php
namespace App\Core\Status;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Infrastructure\InfrastructureManagerInterface;
use App\Core\Database\DatabaseManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Exceptions\SystemStatusException;

class SystemStatusManager implements SystemStatusManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private InfrastructureManagerInterface $infrastructure;
    private DatabaseManagerInterface $database;
    private CacheManagerInterface $cache;
    private array $thresholds;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        InfrastructureManagerInterface $infrastructure,
        DatabaseManagerInterface $database,
        CacheManagerInterface $cache,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->infrastructure = $infrastructure;
        $this->database = $database;
        $this->cache = $cache;
        $this->thresholds = $config['thresholds'];
    }

    /**
     * Get comprehensive system status with security verification
     */
    public function getSystemStatus(): SystemStatus
    {
        $operationId = $this->monitor->startOperation('status.check');

        try {
            // Verify security state first
            $this->verifySecurityState();

            // Collect component statuses
            $status = new SystemStatus([
                'security' => $this->getSecurityStatus(),
                'infrastructure' => $this->getInfrastructureStatus(),
                'database' => $this->getDatabaseStatus(),
                'cache' => $this->getCacheStatus(),
                'resources' => $this->getResourceStatus(),
                'timestamp' => now()
            ]);

            // Validate overall system health
            $this->validateSystemHealth($status);

            return $status;

        } catch (\Throwable $e) {
            $this->handleStatusCheckFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Monitor critical system metrics in real-time
     */
    public function monitorCriticalMetrics(): void
    {
        $operationId = $this->monitor->startOperation('status.monitor');

        try {
            // Monitor security metrics
            $this->monitorSecurityMetrics();

            // Monitor performance metrics
            $this->monitorPerformanceMetrics();

            // Monitor resource usage
            $this->monitorResourceUsage();

            // Check for anomalies
            $this->detectAnomalies();

        } catch (\Throwable $e) {
            $this->handleMonitoringFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Get detailed security status
     */
    private function getSecurityStatus(): array
    {
        return $this->security->executeCriticalOperation(function() {
            return [
                'authentication' => $this->security->verifyAuthenticationSystem(),
                'encryption' => $this->security->verifyEncryptionSystem(),
                'access_control' => $this->security->verifyAccessControl(),
                'audit_system' => $this->security->verifyAuditSystem(),
                'last_security_check' => now()
            ];
        }, ['context' => 'security_status_check']);
    }

    /**
     * Get infrastructure status with performance metrics
     */
    private function getInfrastructureStatus(): array
    {
        $metrics = $this->infrastructure->getPerformanceMetrics();
        
        return [
            'response_time' => $metrics['response_time'],
            'server_load' => $metrics['server_load'],
            'memory_usage' => $metrics['memory_usage'],
            'disk_usage' => $metrics['disk_usage'],
            'network_status' => $metrics['network_status']
        ];
    }

    /**
     * Validate overall system health
     */
    private function validateSystemHealth(SystemStatus $status): void
    {
        // Check security health
        if (!$this->isSecurityHealthy($status->security)) {
            throw new SystemStatusException('Security system health check failed');
        }

        // Check infrastructure health
        if (!$this->isInfrastructureHealthy($status->infrastructure)) {
            throw new SystemStatusException('Infrastructure health check failed');
        }

        // Check resource health
        if (!$this->areResourcesHealthy($status->resources)) {
            throw new SystemStatusException('Resource health check failed');
        }
    }

    /**
     * Monitor security metrics in real-time
     */
    private function monitorSecurityMetrics(): void
    {
        $metrics = $this->security->getSecurityMetrics();

        foreach ($metrics as $metric => $value) {
            if ($value > $this->thresholds['security'][$metric]) {
                $this->handleSecurityThresholdViolation($metric, $value);
            }
            
            $this->monitor->recordMetric("security.$metric", $value);
        }
    }

    /**
     * Monitor performance metrics in real-time
     */
    private function monitorPerformanceMetrics(): void
    {
        $metrics = $this->infrastructure->getPerformanceMetrics();

        foreach ($metrics as $metric => $value) {
            if ($value > $this->thresholds['performance'][$metric]) {
                $this->handlePerformanceThresholdViolation($metric, $value);
            }
            
            $this->monitor->recordMetric("performance.$metric", $value);
        }
    }

    /**
     * Handle security threshold violation
     */
    private function handleSecurityThresholdViolation(string $metric, $value): void
    {
        $this->monitor->triggerAlert('security_threshold_exceeded', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds['security'][$metric]
        ]);
    }

    /**
     * Handle performance threshold violation
     */
    private function handlePerformanceThresholdViolation(string $metric, $value): void
    {
        $this->monitor->triggerAlert('performance_threshold_exceeded', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds['performance'][$metric]
        ]);

        // Attempt automatic optimization
        $this->infrastructure->optimizePerformance($metric);
    }
}
```
