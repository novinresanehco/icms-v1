<?php

namespace App\Core\Infrastructure;

use App\Core\Security\SecurityCoreInterface;
use App\Core\Exception\InfrastructureException;
use App\Core\Monitoring\MonitoringManagerInterface;
use Psr\Log\LoggerInterface;

class InfrastructureCore implements InfrastructureCoreInterface
{
    private SecurityCoreInterface $security;
    private MonitoringManagerInterface $monitor;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityCoreInterface $security,
        MonitoringManagerInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateSystemState(): SystemState
    {
        $stateId = $this->generateStateId();
        
        try {
            $this->security->validateSecureOperation('system:validate', []);
            
            $metrics = $this->collectSystemMetrics();
            $this->validateSystemMetrics($metrics);
            
            $state = $this->analyzeSystemState($metrics);
            $this->validateStateThresholds($state);
            
            $this->logSystemState($stateId, $state);
            
            return $state;

        } catch (\Exception $e) {
            $this->handleSystemFailure($stateId, 'validation', $e);
            throw new InfrastructureException('System validation failed', 0, $e);
        }
    }

    public function optimizeSystem(array $params): OptimizationResult
    {
        $operationId = $this->generateOperationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('system:optimize', $params);
            $this->validateOptimizationParams($params);
            
            $result = $this->executeOptimization($params);
            $this->validateOptimizationResult($result);
            
            $this->logOptimization($operationId, $result);
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOptimizationFailure($operationId, $e);
            throw new InfrastructureException('System optimization failed', 0, $e);
        }
    }

    private function collectSystemMetrics(): array
    {
        return [
            'cpu' => $this->monitor->getCPUMetrics(),
            'memory' => $this->monitor->getMemoryMetrics(),
            'disk' => $this->monitor->getDiskMetrics(),
            'network' => $this->monitor->getNetworkMetrics(),
            'services' => $this->monitor->getServicesStatus()
        ];
    }

    private function validateSystemMetrics(array $metrics): void
    {
        foreach ($metrics as $type => $metric) {
            if (!$this->isMetricValid($type, $metric)) {
                throw new InfrastructureException("Invalid system metric: {$type}");
            }

            if ($this->isMetricCritical($type, $metric)) {
                throw new InfrastructureException("Critical system metric: {$type}");
            }
        }
    }

    private function analyzeSystemState(array $metrics): SystemState
    {
        $state = new SystemState();
        
        $state->setCPUStatus($this->analyzeCPUMetrics($metrics['cpu']));
        $state->setMemoryStatus($this->analyzeMemoryMetrics($metrics['memory']));
        $state->setDiskStatus($this->analyzeDiskMetrics($metrics['disk']));
        $state->setNetworkStatus($this->analyzeNetworkMetrics($metrics['network']));
        $state->setServicesStatus($this->analyzeServicesStatus($metrics['services']));
        
        return $state;
    }

    private function executeOptimization(array $params): OptimizationResult
    {
        $result = new OptimizationResult();
        
        $result->addStep($this->optimizeMemory($params));
        $result->addStep($this->optimizeDisk($params));
        $result->addStep($this->optimizeServices($params));
        
        $this->validateOptimizationSteps($result->getSteps());
        
        return $result;
    }

    private function handleSystemFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->critical('System operation failed', [
            'id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'metrics' => $this->collectSystemMetrics()
        ]);

        $this->executeEmergencyProtocol($id, $operation);
    }

    private function getDefaultConfig(): array
    {
        return [
            'cpu_threshold' => 80,
            'memory_threshold' => 85,
            'disk_threshold' => 90,
            'network_threshold' => 85,
            'optimization_timeout' => 300
        ];
    }
}
