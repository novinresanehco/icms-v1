<?php

namespace App\Core\Performance;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\PerformanceException;
use Psr\Log\LoggerInterface;

class PerformanceMonitor implements PerformanceMonitorInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $metrics = [];
    private array $thresholds = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeThresholds();
    }

    public function startMeasurement(string $context): string
    {
        $measurementId = $this->generateMeasurementId();

        try {
            $this->security->validateContext('performance:measure');

            $this->metrics[$measurementId] = [
                'context' => $context,
                'start_time' => microtime(true),
                'start_memory' => memory_get_usage(true),
                'measurements' => []
            ];

            $this->logMeasurementStart($measurementId, $context);
            return $measurementId;

        } catch (\Exception $e) {
            throw new PerformanceException('Failed to start performance measurement', 0, $e);
        }
    }

    public function recordMetric(
        string $measurementId,
        string $metric,
        float $value
    ): void {
        if (!isset($this->metrics[$measurementId])) {
            throw new PerformanceException('Invalid measurement ID');
        }

        try {
            $this->security->validateContext('performance:record');

            $this->metrics[$measurementId]['measurements'][$metric] = $value;

            if ($this->isThresholdExceeded($metric, $value)) {
                $this->handleThresholdViolation($measurementId, $metric, $value);
            }

            $this->logMetricRecord($measurementId, $metric, $value);

        } catch (\Exception $e) {
            throw new PerformanceException('Failed to record performance metric', 0, $e);
        }
    }

    public function stopMeasurement(string $measurementId): array
    {
        if (!isset($this->metrics[$measurementId])) {
            throw new PerformanceException('Invalid measurement ID');
        }

        try {
            $this->security->validateContext('performance:stop');

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $metrics = $this->calculateFinalMetrics(
                $measurementId,
                $endTime,
                $endMemory
            );

            $this->logMeasurementStop($measurementId, $metrics);
            
            unset($this->metrics[$measurementId]);
            
            return $metrics;

        } catch (\Exception $e) {
            throw new PerformanceException('Failed to stop performance measurement', 0, $e);
        }
    }

    private function calculateFinalMetrics(
        string $measurementId,
        float $endTime,
        int $endMemory
    ): array {
        $measurement = $this->metrics[$measurementId];
        
        return [
            'context' => $measurement['context'],
            'duration' => $endTime - $measurement['start_time'],
            'memory_usage' => $endMemory - $measurement['start_memory'],
            'peak_memory' => memory_get_peak_usage(true),
            'measurements' => $measurement['measurements']
        ];
    }

    private function isThresholdExceeded(string $metric, float $value): bool
    {
        return isset($this->thresholds[$metric]) && 
               $value > $this->thresholds[$metric];
    }

    private function handleThresholdViolation(
        string $measurementId,
        string $metric,
        float $value
    ): void {
        $this->logger->warning('Performance threshold exceeded', [
            'measurement_id' => $measurementId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric]
        ]);

        if ($this->config['enforce_thresholds']) {
            throw new PerformanceException(
                "Performance threshold exceeded for {$metric}"
            );
        }
    }

    private function initializeThresholds(): void
    {
        $this->thresholds = [
            'response_time' => $this->config['thresholds']['response_time'],
            'memory_usage' => $this->config['thresholds']['memory_usage'],
            'cpu_usage' => $this->config['thresholds']['cpu_usage'],
            'query_time' => $this->config['thresholds']['query_time']
        ];
    }

    private function generateMeasurementId(): string
    {
        return uniqid('perf_', true);
    }

    private function logMeasurementStart(
        string $measurementId,
        string $context
    ): void {
        $this->logger->info('Performance measurement started', [
            'measurement_id' => $measurementId,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function logMetricRecord(
        string $measurementId,
        string $metric,
        float $value
    ): void {
        $this->logger->info('Performance metric recorded', [
            'measurement_id' => $measurementId,
            'metric' => $metric,
            'value' => $value,
            'timestamp' => microtime(true)
        ]);
    }

    private function logMeasurementStop(
        string $measurementId,
        array $metrics
    ): void {
        $this->logger->info('Performance measurement completed', [
            'measurement_id' => $measurementId,
            'metrics' => $metrics,
            'timestamp' => microtime(true)
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'thresholds' => [
                'response_time' => 200,    // milliseconds
                'memory_usage' => 67108864, // 64MB
                'cpu_usage' => 70,         // percentage
                'query_time' => 50         // milliseconds
            ],
            'enforce_thresholds' => true,
            'monitoring_enabled' => true,
            'log_level' => 'warning'
        ];
    }
}
