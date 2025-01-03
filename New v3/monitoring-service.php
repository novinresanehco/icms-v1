<?php

namespace App\Core\Monitoring;

/**
 * Critical System Monitoring Service
 * Handles all system health monitoring, metrics collection and alerting
 */
class MonitoringService implements MonitoringInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private AlertManager $alertManager;
    private LogManager $logManager;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        AlertManager $alertManager,
        LogManager $logManager,
        array $config
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->alertManager = $alertManager;
        $this->logManager = $logManager;
        $this->config = $config;
    }

    public function monitor(): HealthStatus
    {
        try {
            // Check system components
            $systemStatus = $this->checkSystemHealth();
            
            // Collect performance metrics
            $performanceMetrics = $this->collectPerformanceMetrics();
            
            // Check security status
            $securityStatus = $this->checkSecurityStatus();
            
            // Verify resource usage
            $resourceStatus = $this->checkResourceUsage();
            
            // Generate health report
            $status = new HealthStatus([
                'system' => $systemStatus,
                'performance' => $performanceMetrics,
                'security' => $securityStatus,
                'resources' => $resourceStatus
            ]);
            
            // Process alerts if needed
            $this->processHealthStatus($status);
            
            return $status;
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
            throw new MonitoringException('Health check failed', 0, $e);
        }
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        try {
            // Validate and sanitize input
            $this->validateMetricData($name, $value, $tags);
            
            // Add timestamp and context
            $metric = [
                'name' => $name,
                'value' => $value,
                'tags' => $tags,
                'timestamp' => microtime(true),
                'context' => $this->getMetricContext()
            ];
            
            // Store metric
            $this->storeMetric($metric);
            
            // Check thresholds
            $this->checkMetricThresholds($metric);
            
            // Update aggregates
            $this->updateMetricAggregates($metric);
            
        } catch (\Exception $e) {
            $this->handleMetricFailure($e, $name, $value, $tags);
        }
    }

    public function getMetrics(string $name, array $options = []): array
    {
        try {
            // Validate request
            $this->security->validateMetricsAccess($name, $options);
            
            // Build query
            $query = $this->buildMetricQuery($name, $options);
            
            // Get raw metrics
            $metrics = $this->database->query($query);
            
            // Process and aggregate
            $processed = $this->processMetrics($metrics, $options);
            
            // Apply transformations
            return $this->transformMetrics($processed, $options);
            
        } catch (\Exception $e) {
            $this->handleMetricFailure($e, $name, null, $options);
            throw new MonitoringException('Failed to retrieve metrics', 0, $e);
        }
    }

    public function startTransaction(string $name): Transaction
    {
        try {
            // Create transaction with context
            $transaction = new Transaction($name, [
                'started_at' => microtime(true),
                'context' => $this->getTransactionContext()
            ]);
            
            // Register transaction
            $this->registerTransaction($transaction);
            
            // Start monitoring
            $this->monitorTransaction($transaction);
            
            return $transaction;
            
        } catch (\Exception $e) {
            $this->handleTransactionFailure($e, $name);
            throw new MonitoringException('Failed to start transaction', 0, $e);
        }
    }

    public function endTransaction(Transaction $transaction, array $context = []): void
    {
        try {
            // Calculate duration
            $duration = microtime(true) - $transaction->getStartTime();
            
            // Record completion
            $this->recordTransactionCompletion($transaction, $duration, $context);
            
            // Check performance
            $this->checkTransactionPerformance($transaction, $duration);
            
            // Update metrics
            $this->updateTransactionMetrics($transaction, $duration);
            
        } catch (\Exception $e) {
            $this->handleTransactionFailure($e, $transaction->getName());
        }
    }

    private function checkSystemHealth(): array
    {
        $status = [];
        
        // Check critical services
        foreach ($this->config['monitored_services'] as $service) {
            $status[$service] = $this->checkServiceHealth($service);
        }
        
        // Check dependencies
        foreach ($this->config['dependencies'] as $dependency) {
            $status['dependencies'][$dependency] = $this->checkDependencyHealth($dependency);
        }
        
        // Check core components
        $status['core'] = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'storage' => $this->checkStorageHealth(),
            'queue' => $this->checkQueueHealth()
        ];
        
        return $status;
    }

    private function collectPerformanceMetrics(): array
    {
        $metrics = [];
        
        // Collect response times
        $metrics['response_times'] = [
            'api' => $this->getAverageResponseTime('api'),
            'web' => $this->getAverageResponseTime('web'),
            'database' => $this->getAverageQueryTime()
        ];
        
        // Collect throughput
        $metrics['throughput'] = [
            'requests' => $this->getRequestThroughput(),
            'transactions' => $this->getTransactionThroughput()
        ];
        
        // Error rates
        $metrics['errors'] = [
            'rate' => $this->getErrorRate(),
            'distribution' => $this->getErrorDistribution()
        ];
        
        return $metrics;
    }

    private function checkSecurityStatus(): array
    {
        return [
            'authentication' => $this->checkAuthenticationStatus(),
            'authorization' => $this->checkAuthorizationStatus(),
            'encryption' => $this->checkEncryptionStatus(),
            'audit' => $this->checkAuditStatus(),
            'threats' => $this->detectSecurityThreats()
        ];
    }

    private function checkResourceUsage(): array
    {
        return [
            'cpu' => [
                'usage' => $this->getCpuUsage(),
                'load' => sys_getloadavg()
            ],
            'memory' => [
                'used' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'storage' => $this->getStorageUsage(),
            'connections' => $this->getConnectionMetrics()
        ];
    }

    private function handleMonitoringFailure(\Exception $e): void
    {
        // Log error with full context
        $this->logManager->error('Monitoring failure', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->getFailureContext()
        ]);

        // Record failure metric
        $this->recordMetric('monitoring.failure', 1, [
            'type' => get_class($e),
            'component' => $this->getFailedComponent($e)
        ]);

        // Alert if critical
        if ($this->isFailureCritical($e)) {
            $this->alertManager->triggerAlert('monitoring_failure', [
                'error' => $e->getMessage(),
                'component' => $this->getFailedComponent($e),
                'impact' => $this->assessFailureImpact($e)
            ]);
        }
    }

    private function processHealthStatus(HealthStatus $status): void
    {
        // Check critical thresholds
        foreach ($status->getMetrics() as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->handleThresholdViolation($metric, $value);
            }
        }

        // Process status changes
        foreach ($status->getChanges() as $component => $change) {