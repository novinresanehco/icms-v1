// src/Core/Infrastructure/SystemMonitor.php
<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;

class SystemMonitor implements MonitorInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;

    public function monitor(): array 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeMonitoring(),
            ['action' => 'system.monitor']
        );
    }

    private function executeMonitoring(): array 
    {
        // Collect system metrics
        $metrics = $this->metrics->collect();
        
        // Analyze and handle alerts
        $analysis = $this->analyzeMetrics($metrics);
        $this->handleAlerts($analysis);
        
        // Cache results
        $this->cacheResults($metrics, $analysis);
        
        return [
            'metrics' => $metrics,
            'analysis' => $analysis,
            'timestamp' => now()
        ];
    }

    private function analyzeMetrics(array $metrics): array 
    {
        return [
            'cpu_status' => $this->analyzeCpuMetrics($metrics['cpu']),
            'memory_status' => $this->analyzeMemoryMetrics($metrics['memory']),
            'disk_status' => $this->analyzeDiskMetrics($metrics['disk']),
            'network_status' => $this->analyzeNetworkMetrics($metrics['network'])
        ];
    }

    private function handleAlerts(array $analysis): void 
    {
        foreach ($analysis as $metric => $status) {
            if ($status['alert_level'] ?? null === 'critical') {
                $this->alerts->triggerCriticalAlert($metric, $status);
            }
        }
    }

    private function cacheResults(array $metrics, array $analysis): void 
    {
        Cache::tags(['system.metrics'])->put('latest', [
            'metrics' => $metrics,
            'analysis' => $analysis,
            'timestamp' => now()
        ], now()->addMinutes(5));
    }
}

// src/Core/Infrastructure/MetricsCollector.php
class MetricsCollector 
{
    public function collect(): array 
    {
        return [
            'cpu' => $this->collectCpuMetrics(),
            'memory' => $this->collectMemoryMetrics(),
            'disk' => $this->collectDiskMetrics(),
            'network' => $this->collectNetworkMetrics()
        ];
    }

    private function collectCpuMetrics(): array 
    {
        return [
            'usage' => sys_getloadavg(),
            'processes' => $this->getProcessCount()
        ];
    }

    private function collectMemoryMetrics(): array 
    {
        $memInfo = $this->parseMemInfo();
        return [
            'total' => $memInfo['MemTotal'] ?? 0,
            'used' => $memInfo['MemTotal'] - $memInfo['MemFree'] ?? 0,
            'cached' => $memInfo['Cached'] ?? 0
        ];
    }
}

// src/Core/Infrastructure/AlertManager.php
class AlertManager 
{
    public function triggerCriticalAlert(string $metric, array $status): void 
    {
        Log::critical("Critical system alert: {$metric}", $status);
        
        // Notify system administrators
        $this->notifyAdmins($metric, $status);
        
        // Take automated actions if configured
        $this->executeAutomatedResponse($metric, $status);
    }

    private function notifyAdmins(string $metric, array $status): void 
    {
        // Implementation depends on notification system configuration
    }

    private function executeAutomatedResponse(string $metric, array $status): void 
    {
        // Implementation depends on automated response configuration
    }
}