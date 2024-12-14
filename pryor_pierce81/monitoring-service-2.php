<?php

namespace App\Core\Services;

use App\Core\Interfaces\MonitoringInterface;
use App\Core\Events\{SystemAlert, PerformanceAlert, SecurityAlert};
use App\Core\Models\{OperationMetrics, SystemMetrics, SecurityMetrics};
use Illuminate\Support\Facades\{Cache, Event, Log};

class MonitoringService implements MonitoringInterface
{
    private array $config;
    private MetricsRepository $metrics;
    private AlertService $alerts;
    private ResourceManager $resources;

    public function __construct(
        array $config,
        MetricsRepository $metrics,
        AlertService $alerts,
        ResourceManager $resources
    ) {
        $this->config = $config;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->resources = $resources;
    }

    public function startOperation(string $operationId): void
    {
        $metrics = new OperationMetrics([
            'operation_id' => $operationId,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => $this->resources->getCpuUsage(),
        ]);

        $this->metrics->saveOperationMetrics($metrics);
        $this->monitorResources($operationId);
    }

    public function trackExecution(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();

            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'status' => 'failure',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function endOperation(string $operationId): void
    {
        $metrics = $this->metrics->getOperationMetrics($operationId);
        $metrics->end_time = microtime(true);
        $metrics->memory_end = memory_get_usage(true);
        $metrics->cpu_end = $this->resources->getCpuUsage();

        $this->validateMetrics($metrics);
        $this->metrics->saveOperationMetrics($metrics);
    }

    public function recordFailure(string $operationId, \Exception $e): void
    {
        $this->metrics->recordFailure($operationId, [
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'timestamp' => microtime(true)
        ]);

        if ($this->isSystemCritical()) {
            $this->handleCriticalFailure($operationId, $e);
        }
    }

    public function getAvailableResources(): array
    {
        return [
            'memory' => $this->resources->getAvailableMemory(),
            'cpu' => $this->resources->getAvailableCpu(),
            'storage' => $this->resources->getAvailableStorage(),
            'connections' => $this->resources->getAvailableConnections()
        ];
    }

    protected function monitorResources(string $operationId): void
    {
        $metrics = new SystemMetrics([
            'operation_id' => $operationId,
            'cpu_usage' => $this->resources->getCpuUsage(),
            'memory_usage' => memory_get_usage(true),
            'disk_usage' => $this->resources->getDiskUsage(),
            'network_usage' => $this->resources->getNetworkUsage()
        ]);

        if ($this->detectResourceIssues($metrics)) {
            $this->handleResourceAlert($metrics);
        }

        $this->metrics->saveSystemMetrics($metrics);
    }

    protected function validateMetrics(OperationMetrics $metrics): void
    {
        if ($metrics->execution_time > $this->config['max_execution_time']) {
            Event::dispatch(new PerformanceAlert('execution_time_exceeded', $metrics));
        }

        if ($metrics->memory_usage > $this->config['max_memory_usage']) {
            Event::dispatch(new PerformanceAlert('memory_usage_exceeded', $metrics));
        }

        if ($metrics->error_count > 0) {
            Event::dispatch(new SystemAlert('operation_errors', $metrics));
        }
    }

    protected function handleCriticalFailure(string $operationId, \Exception $e): void
    {
        Log::critical('Critical system failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        $this->alerts->sendCriticalAlert('SYSTEM_FAILURE', [
            'operation_id' => $operationId,
            'error' => $e->getMessage()
        ]);
    }

    protected function isSystemCritical(): bool
    {
        $metrics = $this->metrics->getCurrentSystemMetrics();
        
        return $metrics->error_rate > $this->config['critical_error_rate'] ||
               $metrics->memory_usage > $this->config['critical_memory_threshold'] ||
               $metrics->cpu_usage > $this->config['critical_cpu_threshold'];
    }

    protected function detectResourceIssues(SystemMetrics $metrics): bool
    {
        return $metrics->cpu_usage > $this->config['cpu_threshold'] ||
               $metrics->memory_usage > $this->config['memory_threshold'] ||
               $metrics->disk_usage > $this->config['disk_threshold'];
    }
}
