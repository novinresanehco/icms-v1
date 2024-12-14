<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MonitoringException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class MonitoringManager implements MonitoringManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private array $config;
    private array $activeMonitors = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        MetricsCollector $metrics,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function startMonitoring(string $component, array $options = []): string
    {
        $monitorId = $this->generateMonitorId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('monitoring:start', [
                'monitor_id' => $monitorId,
                'component' => $component
            ]);

            $this->validateMonitoringRequest($component, $options);
            $monitor = $this->createMonitor($monitorId, $component, $options);
            
            $this->activeMonitors[$monitorId] = $monitor;
            $this->startMetricsCollection($monitor);
            
            $this->logMonitoringStart($monitorId, $component);

            DB::commit();
            return $monitorId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($monitorId, 'start', $e);
            throw new MonitoringException('Monitoring start failed', 0, $e);
        }
    }

    public function stopMonitoring(string $monitorId): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('monitoring:stop', [
                'monitor_id' => $monitorId
            ]);

            $monitor = $this->getActiveMonitor($monitorId);
            $this->stopMetricsCollection($monitor);
            $this->saveMonitoringResults($monitor);
            
            unset($this->activeMonitors[$monitorId]);
            
            $this->logMonitoringStop($monitorId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure($monitorId, 'stop', $e);
            throw new MonitoringException('Monitoring stop failed', 0, $e);
        }
    }

    public function getMetrics(string $component, array $criteria = []): array
    {
        try {
            $this->security->validateSecureOperation('monitoring:metrics', [
                'component' => $component
            ]);

            $this->validateMetricsCriteria($criteria);
            return $this->metrics->getMetrics($component, $criteria);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure(null, 'metrics', $e);
            throw new MonitoringException('Metrics retrieval failed', 0, $e);
        }
    }

    public function checkHealth(string $component): HealthStatus
    {
        try {
            $monitorId = $this->generateMonitorId();
            
            $this->security->validateSecureOperation('monitoring:health', [
                'monitor_id' => $monitorId,
                'component' => $component
            ]);

            $status = $this->performHealthCheck($component);
            $this->logHealthCheck($monitorId, $component, $status);

            return $status;

        } catch (\Exception $e) {
            $this->handleMonitoringFailure(null, 'health', $e);
            throw new MonitoringException('Health check failed', 0, $e);
        }
    }

    private function validateMonitoringRequest(string $component, array $options): void
    {
        if (!isset($this->config['allowed_components'][$component])) {
            throw new MonitoringException('Invalid monitoring component');
        }

        if (!$this->validateMonitoringOptions($options)) {
            throw new MonitoringException('Invalid monitoring options');
        }
    }

    private function validateMonitoringOptions(array $options): bool
    {
        $required = ['interval', 'metrics'];
        
        foreach ($required as $field) {
            if (!isset($options[$field])) {
                return false;
            }
        }

        if ($options['interval'] < $this->config['min_interval']) {
            return false;
        }

        return true;
    }

    private function validateMetricsCriteria(array $criteria): void
    {
        $allowed = ['start_time', 'end_time', 'interval', 'aggregation'];
        
        foreach ($criteria as $key => $value) {
            if (!in_array($key, $allowed)) {
                throw new MonitoringException('Invalid metrics criteria');
            }
        }
    }

    private function createMonitor(string $monitorId, string $component, array $options): Monitor
    {
        return new Monitor([
            'id' => $monitorId,
            'component' => $component,
            'options' => $options,
            'start_time' => time(),
            'status' => MonitoringStatus::ACTIVE
        ]);
    }

    private function startMetricsCollection(Monitor $monitor): void
    {
        $this->metrics->startCollection(
            $monitor->id,
            $monitor->component,
            $monitor->options
        );
    }

    private function stopMetricsCollection(Monitor $monitor): void
    {
        $this->metrics->stopCollection($monitor->id);
    }

    private function saveMonitoringResults(Monitor $monitor): void
    {
        $results = $this->metrics->getCollectionResults($monitor->id);
        
        DB::table('monitoring_results')->insert([
            'monitor_id' => $monitor->id,
            'component' => $monitor->component,
            'start_time' => $monitor->start_time,
            'end_time' => time(),
            'results' => json_encode($results),
            'created_at' => now()
        ]);
    }

    private function performHealthCheck(string $component): HealthStatus
    {
        $status = new HealthStatus();
        
        $metrics = $this->metrics->getRecentMetrics($component);
        $thresholds = $this->config['health_thresholds'][$component] ?? [];
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric])) {
                $status->addCheck(
                    $metric,
                    $this->checkThreshold($metrics[$metric], $threshold)
                );
            }
        }

        return $status;
    }

    private function checkThreshold($value, array $threshold): bool
    {
        return match ($threshold['operator']) {
            '<' => $value < $threshold['value'],
            '>' => $value > $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '=' => $value == $threshold['value'],
            default => false
        };
    }

    private function getActiveMonitor(string $monitorId): Monitor
    {
        if (!isset($this->activeMonitors[$monitorId])) {
            throw new MonitoringException('Monitor not found');
        }

        return $this->activeMonitors[$monitorId];
    }

    private function generateMonitorId(): string
    {
        return uniqid('monitor_', true);
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_components' => [
                'system' => true,
                'database' => true,
                'cache' => true,
                'security' => true,
                'api' => true
            ],
            'min_interval' => 1,
            'health_thresholds' => [
                'system' => [
                    'cpu_usage' => ['operator' => '<', 'value' => 80],
                    'memory_usage' => ['operator' => '<', 'value' => 85],
                    'disk_usage' => ['operator' => '<', 'value' => 90]
                ]
            ]
        ];
    }

    private function handleMonitoringFailure(?string $monitorId, string $operation, \Exception $e): void
    {
        $this->logger->error('Monitoring operation failed', [
            'monitor_id' => $monitorId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
