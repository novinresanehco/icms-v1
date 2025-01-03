<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Alert\AlertManager;
use App\Core\Validation\ValidationService;

class MonitoringService implements MonitoringInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ValidationService $validator;
    private array $thresholds;
    
    public function startOperation(array $context): string
    {
        $operationId = $this->generateOperationId();
        
        try {
            $this->validateContext($context);
            $this->security->validateAccess('monitoring.start');
            
            $this->metrics->initializeOperation($operationId, $context);
            $this->startMonitoring($operationId);
            
            return $operationId;
        } catch (\Exception $e) {
            $this->handleStartFailure($e, $context);
            throw $e;
        }
    }

    public function stopOperation(string $operationId): void
    {
        try {
            $this->security->validateAccess('monitoring.stop');
            $this->validateOperationId($operationId);

            $metrics = $this->metrics->collectOperationMetrics($operationId);
            $this->validateMetrics($metrics);
            $this->alerts->checkThresholds($metrics);

            $this->metrics->finalizeOperation($operationId);
        } catch (\Exception $e) {
            $this->handleStopFailure($e, $operationId);
            throw $e;
        }
    }

    public function track(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            $this->validateOperation($operationId);
            
            $result = $operation();
            
            $this->recordOperationSuccess($operationId, $startTime);
            return $result;
            
        } catch (\Exception $e) {
            $this->recordOperationFailure($operationId, $e, $startTime);
            throw $e;
        }
    }

    public function captureSystemState(): array
    {
        return [
            'metrics' => $this->metrics->getCurrentMetrics(),
            'health' => $this->getSystemHealth(),
            'resources' => $this->getResourceUsage(),
            'services' => $this->getServiceStatus(),
            'security' => $this->getSecurityStatus(),
        ];
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateMonitoringContext($context)) {
            throw new MonitoringException('Invalid monitoring context');
        }
    }

    private function validateOperationId(string $operationId): void
    {
        if (!$this->metrics->operationExists($operationId)) {
            throw new MonitoringException('Invalid operation ID');
        }
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($value > $this->thresholds[$metric]) {
                $this->alerts->triggerThresholdAlert($metric, $value);
            }
        }
    }

    private function startMonitoring(string $operationId): void
    {
        $this->metrics->startMetricsCollection($operationId);
        $this->alerts->initializeMonitoring($operationId);
    }

    private function recordOperationSuccess(string $operationId, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record([
            'operation_id' => $operationId,
            'duration' => $duration,
            'status' => 'success',
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0]
        ]);
    }

    private function recordOperationFailure(string $operationId, \Exception $e, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record([
            'operation_id' => $operationId,
            'duration' => $duration,
            'status' => 'failure',
            'error' => $e->getMessage(),
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0]
        ]);

        $this->alerts->notifyOperationFailure($operationId, $e);
    }

    private function handleStartFailure(\Exception $e, array $context): void
    {
        $this->alerts->notifyMonitoringFailure('start_operation', [
            'error' => $e->getMessage(),
            'context' => $context
        ]);
    }

    private function handleStopFailure(\Exception $e, string $operationId): void
    {
        $this->alerts->notifyMonitoringFailure('stop_operation', [
            'error' => $e->getMessage(),
            'operation_id' => $operationId
        ]);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }
}
