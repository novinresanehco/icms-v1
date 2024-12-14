<?php
namespace App\Core\Monitoring;

class SystemMonitor
{
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private LogManager $logger;
    private AlertManager $alerts;

    public function startOperation(string $type): string
    {
        $id = uniqid('op_', true);
        $this->metrics->initializeOperation($id, $type);
        return $id;
    }

    public function trackOperation(string $id, callable $operation)
    {
        $start = microtime(true);
        
        try {
            $result = $operation();
            $this->metrics->recordSuccess($id, microtime(true) - $start);
            return $result;
        } catch (\Exception $e) {
            $this->metrics->recordFailure($id, microtime(true) - $start);
            $this->handleFailure($id, $e);
            throw $e;
        }
    }

    private function handleFailure(string $id, \Exception $e): void
    {
        $this->logger->logError($e);
        
        if ($this->isCriticalError($e)) {
            $this->alerts->triggerCriticalAlert([
                'operation_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}

class ValidationManager 
{
    private array $validators = [];
    private SecurityManager $security;
    private LogManager $logger;

    public function validate(array $data, string $type): bool
    {
        if (!isset($this->validators[$type])) {
            throw new ValidationException("No validator for type: {$type}");
        }

        try {
            $validator = $this->validators[$type];
            $result = $validator->validate($data);
            
            $this->logger->logValidation([
                'type' => $type,
                'data' => $data,
                'result' => $result
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->logValidationError($e);
            throw new ValidationException('Validation failed', 0, $e);
        }
    }

    public function registerValidator(string $type, Validator $validator): void
    {
        $this->validators[$type] = $validator;
    }
}

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;

    public function recordMetric(string $type, float $value): void
    {
        $this->metrics->record($type, $value);
        
        if ($this->isThresholdExceeded($type, $value)) {
            $this->alerts->triggerPerformanceAlert([
                'type' => $type,
                'value' => $value,
                'threshold' => $this->thresholds[$type]
            ]);
        }
    }

    public function recordResourceUsage(): void
    {
        $memory = memory_get_usage(true);
        $cpu = sys_getloadavg()[0];

        $this->metrics->recordResource([
            'memory' => $memory,
            'cpu' => $cpu,
            'time' => microtime(true)
        ]);

        if ($this->isResourceCritical($memory, $cpu)) {
            $this->alerts->triggerResourceAlert([
                'memory' => $memory,
                'cpu' => $cpu
            ]);
        }
    }
}