<?php

namespace App\Core\Monitoring;

class SystemMonitor implements SystemMonitorInterface
{
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private AlertManager $alerts;
    private MonitoringConfig $config;

    public function startMonitoring(): void
    {
        DB::transaction(function() {
            $this->initializeMonitors();
            $this->startHealthChecks();
            $this->activateAlerts();
            $this->beginMetricsCollection();
        });
    }

    public function checkSystemHealth(): HealthReport
    {
        return new HealthReport([
            'system' => $this->checkSystemMetrics(),
            'security' => $this->checkSecurityStatus(),
            'performance' => $this->checkPerformanceMetrics(),
            'resources' => $this->checkResourceUsage(),
            'services' => $this->checkServiceStatus()
        ]);
    }

    private function initializeMonitors(): void
    {
        foreach ($this->config->getActiveMonitors() as $monitor) {
            $this->initializeMonitor($monitor);
        }
    }

    private function initializeMonitor(string $monitor): void
    {
        match($monitor) {
            'system' => $this->initSystemMonitor(),
            'security' => $this->initSecurityMonitor(),
            'performance' => $this->initPerformanceMonitor(),
            'resources' => $this->initResourceMonitor(),
            'services' => $this->initServiceMonitor()
        };
    }

    private function startHealthChecks(): void
    {
        foreach ($this->config->getHealthChecks() as $check) {
            $this->scheduleHealthCheck($check);
        }
    }

    private function scheduleHealthCheck(array $check): void
    {
        $frequency = $check['frequency'] ?? 60;
        
        scheduler()->job(SystemHealthCheck::class)
            ->args($check)
            ->everySeconds($frequency)
            ->run();
    }

    private function activateAlerts(): void
    {
        foreach ($this->config->getAlertThresholds() as $metric => $threshold) {
            $this->setupAlertThreshold($metric, $threshold);
        }
    }

    private function setupAlertThreshold(string $metric, array $threshold): void
    {
        $this->metrics->setThreshold(
            $metric,
            $threshold['value'],
            $threshold['operator'],
            fn() => $this->handleThresholdAlert($metric, $threshold)
        );
    }

    private function beginMetricsCollection(): void
    {
        foreach ($this->config->getMetrics() as $metric) {
            $this->collectMetric($metric);
        }
    }

    private function collectMetric(array $metric): void
    {
        $frequency = $metric['frequency'] ?? 60;
        
        scheduler()->job(MetricCollection::class)
            ->args($metric)
            ->everySeconds($frequency)
            ->run();
    }

    private function checkSystemMetrics(): array
    {
        return [
            'cpu' => $this->checkCPUUsage(),
            'memory' => $this->checkMemoryUsage(),
            'disk' => $this->checkDiskUsage(),
            'network' => $this->checkNetworkStatus(),
            'load' => $this->checkSystemLoad()
        ];
    }

    private function checkSecurityStatus(): array
    {
        return [
            'authentication' => $this->checkAuthSystem(),
            'authorization' => $this->checkAuthZSystem(),
            'encryption' => $this->checkEncryption(),
            'integrity' => $this->checkSystemIntegrity(),
            'audit' => $this->checkAuditSystem()
        ];
    }

    private function checkPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->checkResponseTimes(),
            'throughput' => $this->checkThroughput(),
            'latency' => $this->checkLatency(),
            'errors' => $this->checkErrorRates(),
            'queues' => $this->checkQueueStatus()
        ];
    }

    private function checkResourceUsage(): array
    {
        return [
            'database' => $this->checkDatabaseUsage(),
            'cache' => $this->checkCacheUsage(),
            'storage' => $this->checkStorageUsage(),
            'connections' => $this->checkConnectionPool(),
            'workers' => $this->checkWorkerStatus()
        ];
    }

    private function checkServiceStatus(): array
    {
        $services = $this->config->getMonitoredServices();
        $status = [];
        
        foreach ($services as $service) {
            $status[$service] = $this->checkService($service);
        }
        
        return $status;
    }

    private function checkService(string $service): ServiceStatus
    {
        try {
            $health = $this->pingService($service);
            $metrics = $this->getServiceMetrics($service);
            $resources = $this->getServiceResources($service);
            
            return new ServiceStatus([
                'healthy' => $health->isHealthy(),
                'metrics' => $metrics,
                'resources' => $resources,
                'lastCheck' => now()
            ]);
            
        } catch (\Exception $e) {
            $this->handleServiceCheckFailure($service, $e);
            
            return new ServiceStatus([
                'healthy' => false,
                'error' => $e->getMessage(),
                'lastCheck' => now()
            ]);
        }
    }

    private function handleThresholdAlert(string $metric, array $threshold): void
    {
        $alert = new ThresholdAlert($metric, $threshold);
        
        $this->alerts->trigger($alert);
        
        if ($threshold['critical']) {
            $this->security->handleCriticalThreshold($metric, $threshold);
        }
    }

    private function handleServiceCheckFailure(string $service, \Exception $e): void
    {
        $this->alerts->trigger(
            new ServiceFailureAlert($service, $e)
        );
        
        $this->metrics->incrementCounter(
            "service.failure.{$service}"
        );
        
        $this->security->logServiceFailure($service, $e);
    }
}
