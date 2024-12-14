<?php

namespace App\Core\Monitoring;

class ServiceMonitor
{
    private Logger $logger;
    private Alerter $alerter;

    public function monitorService(string $service): void
    {
        try {
            // Check service health
            $health = $this->checkServiceHealth($service);
            
            // Monitor performance
            $metrics = $this->collectMetrics($service);
            
            // Track resource usage
            $resources = $this->trackResources($service);
            
            // Log status
            $this->logServiceStatus($service, $health, $metrics, $resources);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($service, $e);
        }
    }

    private function checkServiceHealth(string $service): array
    {
        return [
            'status' => $this->getServiceStatus($service),
            'uptime' => $this->getServiceUptime($service),
            'errors' => $this->getServiceErrors($service)
        ];
    }

    private function collectMetrics(string $service): array
    {
        return [
            'response_time' => $this->getResponseTime($service),
            'memory_usage' => $this->getMemoryUsage($service),
            'cpu_usage' => $this->getCPUUsage($service)
        ];
    }

    private function handleMonitoringFailure(string $service, \Exception $e): void
    {
        $this->logger->critical("Service monitoring failed: $service", [
            'exception' => $e->getMessage(),
            'service' => $service,
            'time' => time()
        ]);
        $this->alerter->sendAlert("Service monitoring failure: $service");
    }
}
