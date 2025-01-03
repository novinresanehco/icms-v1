<?php

namespace App\Core\Monitoring;

/**
 * Core monitoring service for critical operation tracking
 */
class MonitoringService implements MonitoringInterface 
{
    private MetricsCollector $metrics;
    private SecurityLogger $logger;
    private AlertSystem $alerts;
    private PerformanceTracker $performance;
    
    public function __construct(
        MetricsCollector $metrics,
        SecurityLogger $logger,
        AlertSystem $alerts,
        PerformanceTracker $performance
    ) {
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->alerts = $alerts;
        $this->performance = $performance;
    }

    public function trackOperation(Operation $operation): OperationResult 
    {
        $startTime = microtime(true);
        $context = $this->createContext($operation);

        try {
            // Pre-execution monitoring
            $this->performance->startTracking($context);
            $this->logger->logOperationStart($context);

            // Execute with monitoring
            $result = $this->executeWithMetrics($operation, $context);

            // Post-execution validation
            $this->validateResult($result, $context);
            $this->recordMetrics($context, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->handleFailure($e, $context);
            throw $e;
        } finally {
            $this->performance->stopTracking($context);
        }
    }

    protected function executeWithMetrics(Operation $operation, Context $context): OperationResult
    {
        $result = $operation->execute();
        
        // Record core metrics
        $this->metrics->record([
            'operation' => $context->getOperationName(),
            'duration' => $context->getDuration(),
            'memory' => memory_get_peak_usage(true),
            'status' => $result->isSuccessful() ? 'success' : 'failure'
        ]);

        return $result;
    }

    protected function validateResult(OperationResult $result, Context $context): void
    {
        // Validate performance thresholds
        if ($context->getDuration() > 200) {
            $this->alerts->warning('Performance threshold exceeded', [
                'operation' => $context->getOperationName(),
                'duration' => $context->getDuration()
            ]);
        }

        // Check resource usage
        if (memory_get_peak_usage(true) > 128 * 1024 * 1024) {
            $this->alerts->warning('High memory usage detected', [
                'operation' => $context->getOperationName(),
                'memory' => memory_get_peak_usage(true)
            ]);
        }

        // Validate result integrity
        if (!$result->isValid()) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function handleFailure(\Exception $e, Context $context): void
    {
        $this->logger->logError($e, $context);
        
        $this->alerts->error('Operation failed', [
            'operation' => $context->getOperationName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount(
            $context->getOperationName()
        );
    }

    protected function createContext(Operation $operation): Context
    {
        return new OperationContext([
            'name' => get_class($operation),
            'timestamp' => microtime(true),
            'data' => $operation->getData()
        ]);
    }
}

interface MonitoringInterface
{
    public function trackOperation(Operation $operation): OperationResult;
}

class OperationContext implements Context
{
    private array $data;
    private float $startTime;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->startTime = microtime(true);
    }

    public function getOperationName(): string
    {
        return $this->data['name'];
    }

    public function getDuration(): float 
    {
        return microtime(true) - $this->startTime;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
