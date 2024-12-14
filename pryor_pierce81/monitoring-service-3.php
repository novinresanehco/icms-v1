<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MonitoringException;
use Psr\Log\LoggerInterface;

class MonitoringService implements MonitoringInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $metrics = [];
    private array $config;
    private array $activeMonitoring = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function startMonitoring(string $context): string
    {
        $monitoringId = $this->generateMonitoringId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('monitoring:start');
            
            $this->activeMonitoring[$monitoringId] = [
                'context' => $context,
                'start_time' => microtime(true),
                'metrics' => []
            ];

            $this->initializeMetrics($monitoringId);
            $this->logMonitoringStart($monitoringId, $context);

            DB::commit();
            return $monitoringId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure('start', $monitoringId, $e);
            throw $e;
        }
    }

    public function recordMetric(string $monitoringId, string $metric, $value): void
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        try {
            DB::beginTransaction();

            $this->security->validateContext('monitoring:record');
            
            $this->metrics[$monitoringId][$metric] = $value;
            $this->checkThresholds($monitoringId, $metric, $value);
            
            $this->logMetricRecord($monitoringId, $metric, $value);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure('record', $monitoringId, $e);
            throw $e;
        }
    }

    public function stopMonitoring(string $monitoringId): void
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        try {
            DB::beginTransaction();

            $this->security->validateContext('monitoring:stop');
            
            $duration = microtime(true) - $this->activeMonitoring[$monitoringId]['start_time'];
            
            $this->finalizeMetrics($monitoringId, $duration);
            $this->logMonitoringStop($monitoringId, $duration);
            
            unset($this->activeMonitoring[$monitoringId]);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMonitoringFailure('stop', $monitoringId, $e);
            throw $e;
        }
    }

    private function initializeMetrics(string $monitoringId): void
    {
        $this->metrics[$monitoringId] = [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'error_count' => 0
        ];
    }

    private function checkThresholds(string $monitoringId, string $metric, $value): void
    {
        if (isset($this->config['thresholds'][$metric]) && 
            $value > $this->config['thresholds'][$metric]) {
            
            $this->handleThresholdViolation($monitoringId, $metric, $value);
        }
    }

    private function handleThresholdViolation(
        string $monitoringId,
        string $metric,
        $value
    ): void {
        $this->logger->warning('Monitoring threshold exceeded', [
            'monitoring_id' => $monitoringId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->config['thresholds'][$metric]
        ]);

        if ($this->config['enforce_thresholds']) {
            throw new MonitoringException("Threshold exceeded for metric: {$metric}");
        }
    }

    private function generateMonitoringId(): string
    {
        return uniqid('mon_', true);
    }

    private function logMonitoringStart(string $monitoringId, string $context): void
    {
        $this->logger->info('Monitoring started', [
            'monitoring_id' => $monitoringId,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }

    private function logMetricRecord(
        string $monitor