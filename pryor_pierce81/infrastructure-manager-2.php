<?php

namespace App\Core\Infrastructure;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringInterface;
use App\Core\Exception\InfrastructureException;
use Psr\Log\LoggerInterface;

class InfrastructureManager implements InfrastructureManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringInterface $monitor;
    private LoggerInterface $logger;
    private array $services = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function initializeService(string $service): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('infrastructure:initialize');
            $this->validateService($service);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'service_initialization',
                'service' => $service
            ]);

            $this->services[$service] = [
                'status' => 'initializing',
                'started_at' => microtime(true),
                'metrics' => []
            ];

            $this->executeServiceInitialization($service);
            $this->verifyServiceHealth($service);

            $this->services[$service]['status'] = 'running';
            
            $this->logServiceInitialization($operationId, $service);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleServiceFailure($operationId, $service, 'initialization', $e);
            throw new InfrastructureException("Service initialization failed: {$service}", 0, $e);
        }
    }

    public function monitorService(string $service): array
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateContext('infrastructure:monitor');
            $this->validateServiceExists($service);

            $metrics = $this->collectServiceMetrics($service);
            $this->validateServiceMetrics($service, $metrics);

            $this->logServiceMetrics($operationId, $service, $metrics);
            return $metrics;

        } catch (\Exception $e) {
            $this->handleServiceFailure($operationId, $service, 'monitoring', $e);
            throw new InfrastructureException("Service monitoring failed: {$service}", 0, $e);
        }
    }

    public function stopService(string $service): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('infrastructure:stop');
            $this->validateServiceExists($service);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'service_shutdown',
                'service' => $service
            ]);

            $this->executeServiceShutdown($service);
            $this->verifyServiceStopped($service);

            unset($this->services[$service]);

            $this->logServiceShutdown($operationId, $service);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleServiceFailure($operationId, $service, 'shutdown', $e);
            throw new InfrastructureException("Service shutdown failed: {$service}", 0, $e);
        }
    }

    private function validateService(string $service): void
    {
        if (isset($this->services[$service])) {
            throw new InfrastructureException("Service already initialized: {$service}");
        }

        if (!in_array($service, $this->config['allowed_services'])) {
            throw new InfrastructureException("Invalid service: {$service}");
        }
    }

    private function validateServiceExists(string $service): void
    {
        if (!isset($this->services[$service])) {
            throw new InfrastructureException("Service not initialized: {$service}");
        }
    }

    private function executeServiceInitialization(string $service): void
    {
        $config = $this->config['services'][$service] ?? [];
        
        // Initialize resources
        $this->allocateResources($service, $config);
        
        // Configure service
        $this->configureService($service, $config);
        
        // Start monitoring
        $this->initializeMonitoring($service);
    }

    private function executeServiceShutdown(string $service): void
    {
        // Stop monitoring
        $this->stopMonitoring($service);
        
        // Release resources
        $this->releaseResources($service);
        
        // Cleanup
        $this->cleanupService($service);
    }

    private function verifyServiceHealth(string $service): void
    {
        $metrics = $this->collectServiceMetrics($service);
        
        foreach ($metrics as $metric => $value) {
            if ($value > $this->config['thresholds'][$metric]) {
                throw new InfrastructureException(
                    "Service health check failed: {$service}, {$metric}"
                );
            }
        }
    }

    private function verifyServiceStopped(string $service): void
    {
        $resources = $this->checkResourceUsage($service);
        
        if (!empty($resources['active'])) {
            throw new InfrastructureException(
                "Service resources still active: {$service}"
            );
        }
    }

    private function collectServiceMetrics(string $service): array
    {
        return [
            'cpu_usage' => $this->getCpuUsage($service),
            'memory_usage' => $this->getMemoryUsage($service),
            'active_connections' => $this->getActiveConnections($service),
            'response_time' => $this->getResponseTime($service)
        ];
    }

    private function validateServiceMetrics(string $service, array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($value > $this->config['thresholds'][$metric]) {
                $this->handleMetricViolation($service, $metric, $value);
            }
        }
    }

    private function handleMetricViolation(
        string $service,
        string $metric,
        float $value
    ): void {
        $this->logger->warning('Service metric threshold exceeded', [
            'service' => $service,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->config['thresholds'][$metric]
        ]);

        if ($this->config['enforce_thresholds']) {
            throw new InfrastructureException(
                "Service metric violation: {$service}, {$metric}"
            );
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('infra_', true);
    }

    private function logServiceInitialization(
        string $operationId,
        string $service
    ): void {
        $this->logger->info('Service initialized', [
            'operation_id' => $operationId,
            'service' => $service,
            'config' => $this->config['services'][$service] ?? [],
            'timestamp' => microtime(true)
        ]);
    }

    private function logServiceMetrics(
        string $operationId,
        string $service,
        array $metrics
    ): void {
        $this->logger->info('Service metrics collected', [
            'operation_id' => $operationId,
            'service' => $service,
            'metrics' => $metrics,
            'timestamp' => microtime(true)
        ]);
    }

    private function logServiceShutdown(
        string $operationId,
        string $service
    ): void {
        $this->logger->info('Service stopped', [
            'operation_id' => $operationId,
            'service' => $service,
            'uptime' => microtime(true) - $this->services[$service]['started_at'],
            'timestamp' => microtime(true)
        ]);
    }

    private function handleServiceFailure(
        string $operationId,
        string $service,
        string $operation,
        \Exception $e
    ): void {
        $this->logger->error('Service operation failed', [
            'operation_id' => $operationId,
            'service' => $service,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_services' => [
                'web',
                'queue',
                'cache',
                'storage'
            ],
            'thresholds' => [
                'cpu_usage' => 70,
                'memory_usage' => 80,
                'active_connections' => 1000,
                'response_time' => 200
            ],
            'enforce_thresholds' => true,
            'monitoring_interval' => 60,
            'auto_recovery' => true
        ];
    }
}
