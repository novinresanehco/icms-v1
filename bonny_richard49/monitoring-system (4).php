<?php

namespace App\Core\Monitoring;

final class MonitoringSystem 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private LogManager $logger;
    private ValidationService $validator;
    private SecurityManager $security;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        LogManager $logger,
        ValidationService $validator,
        SecurityManager $security
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->logger = $logger;
        $this->validator = $validator;
        $this->security = $security;
    }

    public function monitorOperation(string $operationId): void 
    {
        try {
            // Start metrics collection
            $this->metrics->startCollection($operationId);

            // Initialize monitoring
            $this->initializeMonitoring($operationId);

            // Monitor critical metrics
            $this->monitorCriticalMetrics($operationId);

            // Check security thresholds
            $this->checkSecurityThresholds($operationId);

        } catch (\Throwable $e) {
            $this->handleMonitoringFailure($e, $operationId);
            throw $e;
        }
    }

    public function collectPerformanceMetrics(): array 
    {
        return [
            'system' => $this->collectSystemMetrics(),
            'application' => $this->collectApplicationMetrics(),
            'database' => $this->collectDatabaseMetrics(),
            'cache' => $this->collectCacheMetrics(),
            'security' => $this->collectSecurityMetrics()
        ];
    }

    public function checkSystemHealth(): HealthStatus 
    {
        $metrics = $this->collectPerformanceMetrics();
        $issues = [];

        // Check each subsystem
        foreach ($metrics as $system => $data) {
            $status = $this->validateSubsystem($system, $data);
            if (!$status->isHealthy()) {
                $issues[] = $status;
            }
        }

        return new HealthStatus(
            healthy: empty($issues),
            issues: $issues,
            timestamp: microtime(true)
        );
    }

    private function monitorCriticalMetrics(string $operationId): void 
    {
        $metrics = [
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'request_time' => $this->metrics->getRequestTime(),
            'error_rate' => $this->metrics->getErrorRate(),
            'active_connections' => $this->metrics->getActiveConnections()
        ];

        foreach ($metrics as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->handleThresholdViolation($metric, $value, $operationId);
            }
        }
    }

    private function checkSecurityThresholds(string $operationId): void 
    {
        $metrics = [
            'failed_logins' => $this->security->getFailedLoginCount(),
            'suspicious_ips' => $this->security->getSuspiciousIpCount(),
            'invalid_tokens' => $this->security->getInvalidTokenCount(),
            'blocked_requests' => $this->security->getBlockedRequestCount()
        ];

        foreach ($metrics as $metric => $value) {
            if ($this->isSecurityThresholdExceeded($metric, $value)) {
                $this->handleSecurityViolation($metric, $value, $operationId);
            }
        }
    }

    private function handleThresholdViolation(string $metric, $value, string $operationId): void 
    {
        // Log violation
        $this->logger->warning('Threshold violation detected', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->getThreshold($metric),
            'operation_id' => $operationId
        ]);

        // Trigger alert
        $this->alerts->triggerThresholdAlert($metric, $value);

        // Take corrective action
        $this->executeCorrectiveAction($metric, $value);
    }

    private function handleSecurityViolation(string $metric, $value, string $operationId): void 
    {
        // Log security violation
        $this->logger->critical('Security threshold violation', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->getSecurityThreshold($metric),
            'operation_id' => $operationId
        ]);

        // Trigger security alert
        $this->alerts->triggerSecurityAlert($metric, $value);

        // Execute security protocol
        $this->executeSecurityProtocol($metric, $value);
    }

    private function collectSystemMetrics(): array 
    {
        return [
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'disk_usage' => $this->metrics->getDiskUsage(),
            'network_io' => $this->metrics->getNetworkIO(),
            'system_load' => sys_getloadavg()
        ];
    }

    private function validateSubsystem(string $system, array $data): SubsystemStatus 
    {
        return match($system) {
            'system' => $this->validateSystemMetrics($data),
            'application' => $this->validateApplicationMetrics($data),
            'database' => $this->validateDatabaseMetrics($data),
            'cache' => $this->validateCacheMetrics($data),
            'security' => $this->validateSecurityMetrics($data),
            default => throw new \InvalidArgumentException("Unknown subsystem: {$system}")
        };
    }
}
