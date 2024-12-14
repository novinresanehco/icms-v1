// File: app/Core/ApiGateway/Monitoring/ApiMonitor.php
<?php

namespace App\Core\ApiGateway\Monitoring;

class ApiMonitor
{
    protected MetricsCollector $metrics;
    protected HealthChecker $healthChecker;
    protected AlertManager $alertManager;
    protected MonitorConfig $config;

    public function monitor(Request $request, Response $response): void
    {
        // Collect metrics
        $metrics = $this->collectMetrics($request, $response);
        
        // Check health
        $health = $this->checkHealth($metrics);
        
        // Process alerts
        if (!$health->isHealthy()) {
            $this->processAlerts($health);
        }
        
        // Store metrics
        $this->storeMetrics($metrics);
    }

    protected function collectMetrics(Request $request, Response $response): array
    {
        return [
            'response_time' => $this->calculateResponseTime($request, $response),
            'status_code' => $response->getStatusCode(),
            'endpoint' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'client_ip' => $request->getClientIp(),
            'timestamp' => now()
        ];
    }

    protected function processAlerts(HealthStatus $health): void
    {
        foreach ($health->getIssues() as $issue) {
            $this->alertManager->dispatch(new HealthAlert($issue));
        }
    }
}
