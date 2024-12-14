<?php

namespace App\Core\System;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\SystemException;
use App\Core\Monitoring\MonitoringManagerInterface;
use Psr\Log\LoggerInterface;

class SystemCore implements SystemCoreInterface
{
    private SecurityManagerInterface $security;
    private MonitoringManagerInterface $monitor;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringManagerInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateSystemState(): bool
    {
        $validationId = $this->generateValidationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateOperation('system:validate', []);
            
            $metrics = $this->collectSystemMetrics();
            $this->validateSystemMetrics($metrics);
            
            $state = $this->analyzeSystemState($metrics);
            $this->validateSystemState($state);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSystemFailure($validationId, $e);
            throw new SystemException('System validation failed', 0, $e);
        }
    }

    public function monitorSystem(): void
    {
        $monitoringId = $this->generateMonitoringId();
        
        try {
            $this->security->validateOperation('system:monitor', []);
            
            $this->startSystemMonitoring($monitoringId);
            $this->configureMonitors();
            $this->initializeAlerts();
            
            $this->monitor->startMonitoring($monitoringId);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($monitoringId, $e);
            throw new SystemException('System monitoring failed', 0, $e);
        }
    }

    public function optimizeSystem(array $params): bool
    {
        $optimizationId = $this->generateOptimizationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateOperation('system:optimize', $params);
            
            $this->validateOptimizationParams($params);
            $this->executeOptimization($params);
            $this->verifyOptimizationResults();
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOptimizationFailure($optimizationId, $e);
            throw new SystemException('System optimization failed', 0, $e);
        }
    }

    private function collectSystemMetrics(): array
    {
        return [
            'performance' => $this->monitor->getPerformanceMetrics(),
            'resources' => $this->monitor->getResourceMetrics(),
            'security' => $this->monitor->getSecurityMetrics(),
            'stability' => $this->monitor->getStabilityMetrics()
        ];
    }

    private function validateSystemMetrics(array $metrics): void
    {
        foreach ($metrics as $type => $metric) {
            if (!$this->isMetricValid($type, $metric)) {
                throw new SystemException("Invalid system metric: {$type}");
            }

            if ($this->isMetricCritical($type, $metric)) {
                throw new SystemException("Critical system metric: {$type}");
            }
        }
    }

    private function startSystemMonitoring(string $monitoringId): void
    {
        $this->initializeMonitors();
        $this->configureAlertThresholds();
        $this->startMetricsCollection();
    }

    private function handleSystemFailure(string $id, \Exception $e): void
    {
        $this->logger->critical('System failure', [
            'id' => $id,
            'error' => $e->getMessage(),
            'metrics' => $this->collectSystemMetrics()
        ]);

        $this->executeEmergencyProtocol($id);
    }

    private function getDefaultConfig(): array
    {
        return [
            'monitoring_interval' => 60,
            'optimization_timeout' => 300,
            'critical_thresholds' => [
                'cpu' => 90,
                'memory' => 85,
                'disk' => 95,
                'load' => 80
            ],
            'alert_thresholds' => [
                'performance' => 70,
                'resources' => 75,
                'security' => 85
            ]
        ];
    }
}
