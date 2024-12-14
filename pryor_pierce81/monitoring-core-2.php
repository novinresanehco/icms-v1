<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityCoreInterface;
use App\Core\Exception\MonitoringException;
use Psr\Log\LoggerInterface;

class MonitoringManager implements MonitoringManagerInterface
{
    private SecurityCoreInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityCoreInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function startMonitoring(string $context): string
    {
        $monitorId = $this->generateMonitorId();
        
        try {
            $this->security->validateSecureOperation('monitoring:start', ['context' => $context]);
            
            $this->initializeMonitoring($monitorId, $context);
            $this->startMetricsCollection($monitorId);
            
            $this->logMonitoringStart($monitorId, $context);
            
            return $monitorId;

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($monitorId, 'start', $e);
            throw new MonitoringException('Failed to start monitoring', 0, $e);
        }
    }

    public function collectMetrics(string $monitorId): array
    {
        try {
            $this->security->validateSecureOperation('monitoring:collect', ['monitor_id' => $monitorId]);
            $this->validateMonitoringSession($monitorId);
            
            $metrics = [
                'system' => $this->collectSystemMetrics(),
                'performance' => $this->collectPerformanceMetrics(),
                'security' => $this->collectSecurityMetrics(),
                'resources' => $this->collectResourceMetrics()
            ];
            
            $this->validateMetrics($metrics);
            $this->storeMetrics($monitorId, $metrics);
            
            return $metrics;

        } catch (\Exception $e) {
            $this->handleMetricsFailure($monitorId, $e);
            throw new MonitoringException('Failed to collect metrics', 0, $e);
        }
    }

    private function initializeMonitoring(string $monitorId, string $context): void
    {
        $this->metrics[$monitorId] = [
            'context' => $context,
            'start_time' => microtime(true),
            'metrics' => [],
            'alerts' => []
        ];
    }

    private function startMetricsCollection(string $monitorId): void
    {
        $collectors = $this->initializeCollectors($monitorId);
        
        foreach ($collectors as $collector) {
            $collector->start();
        }
        
        $this->validateCollectors($collectors);
    }

    private function collectSystemMetrics(): array
    {
        return [
            'cpu' => $this->getCPUMetrics(),
            'memory' => $this->getMemoryMetrics(),
            'disk' => $this->getDiskMetrics(),
            'network' => $this->getNetworkMetrics()
        ];
    }

    private function collectPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->getResponseTimeMetrics(),
            'throughput' => $this->getThroughputMetrics(),
            'error_rate' => $this->getErrorRateMetrics(),
            'latency' => $this->getLatencyMetrics()
        ];
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $type => $metric) {
            if (!$this->isMetricValid($type, $metric)) {
                throw new MonitoringException("Invalid metric type: {$type}");
            }

            if ($this->isMetricCritical($type, $metric)) {
                $this->handleCriticalMetric($type, $metric);
            }
        }
    }

    private function handleMonitoringFailure(string $monitorId, string $operation, \Exception $e): void
    {
        $this->logger->error('Monitoring operation failed', [
            'monitor_id' => $monitorId,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'metrics_interval' => 60,
            'storage_retention' => 86400,
            'alert_thresholds' => [
                'cpu' => 80,
                'memory' => 85,
                'disk' => 90,
                'error_rate' => 1
            ],
            'collectors' => [
                'system' => SystemCollector::class,
                'performance' => PerformanceCollector::class,
                'security' => SecurityCollector::class
            ]
        ];
    }
}
