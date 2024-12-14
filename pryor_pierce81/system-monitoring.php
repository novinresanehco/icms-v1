<?php

namespace App\Core\Monitor;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Log, Cache, DB};

final class CriticalMonitor
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private SystemVerifier $verifier;
    private AlertSystem $alerts;
    private array $thresholds;

    public function monitorSystem(): void
    {
        $monitorId = uniqid('mon_', true);
        
        try {
            $metrics = $this->collectMetrics();
            $this->verifySystemState($metrics);
            $this->recordMetrics($monitorId, $metrics);
            
            if ($this->detectAnomalies($metrics)) {
                $this->handleAnomalies($metrics);
            }
        } catch (\Throwable $e) {
            $this->handleMonitoringFailure($e, $monitorId);
            throw $e;
        }
    }

    private function collectMetrics(): array
    {
        return [
            'memory' => $this->metrics->getMemoryUsage(),
            'cpu' => $this->metrics->getCpuUsage(),
            'queries' => $this->metrics->getDatabaseMetrics(),
            'cache' => $this->metrics->getCacheMetrics(),
            'errors' => $this->metrics->getErrorMetrics()
        ];
    }

    private function verifySystemState(array $metrics): void
    {
        if (!$this->verifier->validateState($metrics)) {
            throw new SystemStateException('Critical system state detected');
        }
    }

    private function detectAnomalies(array $metrics): bool
    {
        foreach ($metrics as $key => $value) {
            if ($value > $this->thresholds[$key]) {
                return true;
            }
        }
        return false;
    }
}

final class MetricsCollector
{
    public function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    public function getCpuUsage(): array
    {
        return [
            'load' => sys_getloadavg(),
            'processes' => $this->getProcessCount()
        ];
    }

    public function getDatabaseMetrics(): array
    {
        return [
            'queries' => DB::getQueryLog(),
            'connections' => DB::getConnections(),
            'transactions' => $this->getTransactionCount()
        ];
    }

    private function getTransactionCount(): int
    {
        return DB::transactionLevel();
    }
}

final class SystemVerifier
{
    private array $criticalLimits;
    
    public function validateState(array $metrics): bool
    {
        foreach ($this->criticalLimits as $metric => $limit) {
            if ($metrics[$metric] > $limit) {
                $this->logCriticalState($metric, $metrics[$metric]);
                return false;
            }
        }
        return true;
    }

    private function logCriticalState(string $metric, $value): void
    {
        Log::critical('Critical system state', [
            'metric' => $metric,
            'value' => $value,
            'limit' => $this->criticalLimits[$metric],
            'timestamp' => microtime(true)
        ]);
    }
}

final class AlertSystem
{
    public function triggerAlert(string $type, array $context): void
    {
        Log::emergency("System Alert: $type", [
            'context' => $context,
            'timestamp' => microtime(true),
            'severity' => $this->calculateSeverity($context)
        ]);
    }

    private function calculateSeverity(array $context): string
    {
        if ($context['memory']['current'] > $context['memory']['limit'] * 0.9) {
            return 'CRITICAL';
        }
        if ($context['cpu']['load'][0] > 90) {
            return 'HIGH';
        }
        return 'MEDIUM';
    }
}

class SystemStateException extends \Exception {}
