<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private SecurityManager $security;
    private LogManager $logger;

    public function __construct(
        MetricsCollector $metrics,
        AlertSystem $alerts,
        SecurityManager $security,
        LogManager $logger
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->security = $security;
        $this->logger = $logger;
    }

    public function monitorOperation(string $operationId, callable $operation)
    {
        $context = $this->initializeContext($operationId);
        $startTime = microtime(true);

        try {
            $this->preExecutionCheck($context);
            $result = $operation();
            $this->postExecutionValidation($result, $context);
            
            $this->recordSuccess($context, microtime(true) - $startTime);
            return $result;

        } catch (\Throwable $e) {
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function preExecutionCheck(array $context): void
    {
        $this->validateSystemState();
        $this->checkSecurityStatus();
        $this->verifyResourceAvailability();
    }

    private function validateSystemState(): void
    {
        $status = [
            'cpu_usage' => sys_getloadavg()[0],
            'memory_usage' => memory_get_usage(true),
            'disk_space' => disk_free_space('/'),
            'connection_pool' => DB::getPoolStatus()
        ];

        foreach ($status as $metric => $value) {
            if (!$this->isWithinThreshold($metric, $value)) {
                throw new SystemStateException("System state validation failed: $metric");
            }
        }
    }

    private function checkSecurityStatus(): void
    {
        if (!$this->security->verifyIntegrity()) {
            throw new SecurityException('Security integrity check failed');
        }
    }

    private function verifyResourceAvailability(): void
    {
        $resources = [
            'database' => DB::getPdo(),
            'cache' => Cache::connection(),
            'storage' => Storage::disk('local'),
            'queue' => Queue::connection()
        ];

        foreach ($resources as $name => $resource) {
            if (!$resource->isAvailable()) {
                throw new ResourceException("Resource unavailable: $name");
            }
        }
    }

    private function postExecutionValidation($result, array $context): void
    {
        $this->validateResult($result);
        $this->verifySystemConsistency();
        $this->checkPerformanceMetrics($context);
    }

    private function validateResult($result): void
    {
        if (!$this->isValidResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function verifySystemConsistency(): void
    {
        $this->security->verifySystemState();
        $this->checkDatabaseConsistency();
        $this->validateCacheIntegrity();
    }

    private function checkPerformanceMetrics(array $context): void
    {
        $metrics = $this->metrics->collect($context['operation_id']);
        
        if ($metrics['response_time'] > $this->config['max_response_time']) {
            $this->alerts->performanceWarning($metrics);
        }

        if ($metrics['memory_usage'] > $this->config['max_memory_usage']) {
            $this->alerts->resourceWarning($metrics);
        }
    }

    private function recordSuccess(array $context, float $duration): void
    {
        $this->metrics->record([
            'operation_id' => $context['operation_id'],
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'status' => 'success'
        ]);

        $this->logger->info('Operation completed successfully', [
            'context' => $context,
            'metrics' => $this->metrics->get($context['operation_id'])
        ]);
    }

    private function handleFailure(\Throwable $e, array $context): void
    {
        $this->logger->error('Operation failed', [
            'context' => $context,
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ],
            'system_state' => $this->captureSystemState()
        ]);

        $this->metrics->increment('operation_failures');
        $this->alerts->criticalError($e, $context);
    }

    private function captureSystemState(): array
    {
        return [
            'cpu_usage' => sys_getloadavg(),
            'memory_usage' => memory_get_usage(true),
            'disk_space' => disk_free_space('/'),
            'connections' => DB::getConnectionsStatus(),
            'cache_status' => Cache::getStatus(),
            'queue_size' => Queue::size()
        ];
    }

    private function isWithinThreshold(string $metric, $value): bool
    {
        return $value <= ($this->config['thresholds'][$metric] ?? PHP_FLOAT_MAX);
    }

    private function isValidResult($result): bool
    {
        return $result !== null 
            && $this->validateResultStructure($result)
            && $this->validateResultData($result);
    }

    private function validateResultStructure($result): bool
    {
        return is_array($result) 
            ? $this->validateArrayStructure($result)
            : $this->validateObjectStructure($result);
    }

    private function validateResultData($result): bool
    {
        return $this->security->validateData($result)
            && $this->validateDataIntegrity($result)
            && $this->checkBusinessRules($result);
    }
}
