<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Core\Exception\MonitoringException;

class MonitoringService implements MonitoringInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private array $config;
    private array $activeOperations = [];

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

    public function startOperation(Operation $operation): string
    {
        $monitoringId = $this->generateMonitoringId();

        try {
            // Security check
            $this->security->validateOperation('monitoring:start', $operation->getType());

            // Record start
            $this->activeOperations[$monitoringId] = [
                'operation' => $operation,
                'start_time' => microtime(true),
                'metrics' => []
            ];

            // Initialize metrics
            $this->initializeMetrics($monitoringId);

            // Log start
            $this->logOperationStart($monitoringId, $operation);

            return $monitoringId;

        } catch (\Exception $e) {
            throw new MonitoringException('Failed to start monitoring', 0, $e);
        }
    }

    public function recordMetric(
        string $monitoringId,
        string $metric,
        $value
    ): void {
        if (!isset($this->activeOperations[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        try {
            $this->metrics->record($monitoringId, $metric, $value);
            
            $this->activeOperations[$monitoringId]['metrics'][$metric] = $value;

            if ($this->isThresholdExceeded($metric, $value)) {
                $this->handleThresholdViolation($monitoringId, $metric, $value);
            }

        } catch (\Exception $e) {
            throw new MonitoringException('Failed to record metric', 0, $e);
        }
    }

    public function stopOperation(string $monitoringId): void
    {
        if (!isset($this->activeOperations[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        try {
            $operation = $this->activeOperations[$monitoringId]['operation'];
            $startTime = $this->activeOperations[$monitoringId]['start_time'];
            $duration = microtime(true) - $startTime;

            // Record final metrics
            $this->recordFinalMetrics($monitoringId, $duration);

            // Log completion
            $this->logOperationComplete($monitoringId, $operation, $duration);

            // Cleanup
            unset($this->activeOperations[$monitoringId]);

        } catch (\Exception $e) {
            throw new MonitoringException('Failed to stop monitoring', 0, $e);
        }
    }

    private function initializeMetrics(string $monitoringId): void
    {
        $this->metrics->initialize($monitoringId, [
            'cpu_usage' => 0,
            'memory_usage' => 0,
            'error_count' => 0
        ]);
    }

    private function recordFinalMetrics(string $monitoringId, float $duration): void
    {
        $this->metrics->record($monitoringId, 'duration', $duration);
        $this->metrics->record($monitoringId, 'memory_peak', memory_get_peak_usage(true));
    }

    private function isThresholdExceeded(string $metric, $value): bool
    {
        if (!isset($this->config['thresholds'][$metric])) {
            return false;
        }

        return $value > $this->config['thresholds'][$metric];
    }

    private function handleThresholdViolation(
        string $monitoringId,
        string $metric,
        $value
    ): void {
        $operation = $this->activeOperations[$monitoringId]['operation'];

        $this->logger->warning('Metric threshold exceeded', [
            'monitoring_id' => $monitoringId,
            'operation_type' => $operation->getType(),
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->config['thresholds'][$metric]
        ]);

        $this->security->logSecurityEvent('threshold_exceeded', [
            'monitoring_id' => $monitoringId,
            'metric' => $metric,
            'value' => $value
        ]);

        if ($this->config['enforce_thresholds']) {
            throw new MonitoringException("Metric threshold exceeded: {$metric}");
        }
    }

    private function generateMonitoringId(): string
    {
        return uniqid('mon_', true);
    }

    private function logOperationStart(
        string $monitoringId,
        Operation $operation
    ): void {
        $this->logger->info('Started monitoring operation', [
            'monitoring_id' => $monitoringId,
            'operation_type' => $operation->getType(),
            'start_time' => date('Y-m-d H:i:s')
        ]);
    }

    private function logOperationComplete(
        string $monitoringId,
        Operation $operation,
        float $duration
    ): void {
        $this->logger->info('Completed monitoring operation', [
            'monitoring_id' => $monitoringId,
            'operation_type' => $operation->getType(),
            'duration' => $duration,
            'metrics' => $this->activeOperations[$monitoringId]['metrics']
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'thresholds' => [
                'cpu_usage' => 70,
                'memory_usage' => 80,
                'duration' => 30,
                'error_count' => 5
            ],
            'enforce_thresholds' => true,
            'metrics_retention' => 86400
        ];
    }
}
