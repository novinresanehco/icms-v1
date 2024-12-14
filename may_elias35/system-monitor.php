<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Exceptions\MonitoringException;
use Illuminate\Support\Facades\Log;

class SystemMonitor implements MonitoringInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private array $activeOperations = [];
    private array $config;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function startOperation(string $type): string
    {
        $operationId = $this->generateOperationId();
        
        $this->activeOperations[$operationId] = [
            'type' => $type,
            'started_at' => microtime(true),
            'status' => 'active',
            'metrics' => []
        ];

        $this->recordOperationStart($operationId);
        
        return $operationId;
    }

    public function endOperation(string $operationId): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException('Invalid operation ID');
        }

        $operation = $this->activeOperations[$operationId];
        $duration = microtime(true) - $operation['started_at'];

        $this->recordOperationEnd($operationId, $duration);
        
        unset($this->activeOperations[$operationId]);
    }

    public function recordMetric(string $operationId, string $metric, $value): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException('Invalid operation ID');
        }

        $this->activeOperations[$operationId]['metrics'][$metric] = $value;
        
        $this->metrics->record($operationId, $metric, $value);
    }

    public function recordSuccess(string $operationId, array $data = []): void
    {
        $this->validateOperation($operationId);
        
        $this->activeOperations[$operationId]['status'] = 'success';
        $this->activeOperations[$operationId]['data'] = $data;

        $this->metrics->incrementSuccess(
            $this->activeOperations[$operationId]['type']
        );
    }

    public function recordFailure(string $operationId, \Exception $e): void
    {
        $this->validateOperation($operationId);
        
        $this->activeOperations[$operationId]['status'] = 'failed';
        $this->activeOperations[$operationId]['error'] = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ];

        $this->metrics->incrementFailure(
            $this->activeOperations[$operationId]['type']
        );

        $this->logFailure($operationId, $e);
    }

    public function verifySystemState(): bool
    {
        $state = $this->captureSystemState();
        
        return $this->validateSystemMetrics($state['metrics']) &&
               $this->validateSystemSecurity($state['security']) &&
               $this->validateSystemResources($state['resources']);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function recordOperationStart(string $operationId): void
    {
        $this->metrics->incrementOperationCount(
            $this->activeOperations[$operationId]['type']
        );

        Log::info("Operation started", [
            'operation_id' => $operationId,
            'type' => $this->activeOperations[$operationId]['type']
        ]);
    }

    private function recordOperationEnd(string $operationId, float $duration): void
    {
        $operation = $this->activeOperations[$operationId];
        
        $this->metrics->recordDuration(
            $operation['type'],
            $duration
        );

        Log::info("Operation completed", [
            'operation_id' => $operationId,
            'type' => $operation['type'],
            'duration' => $duration,
            'status' => $operation['status']
        ]);
    }

    private function validateOperation(string $operationId): void
    {
        if (!isset($this->activeOperations[$operationId])) {
            throw new MonitoringException('Invalid operation ID');
        }
    }

    private function logFailure(string $operationId, \Exception $e): void
    {
        $operation = $this->activeOperations[$operationId];
        
        Log::error("Operation failed", [
            'operation_id' => $operationId,
            'type' => $operation['type'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'metrics' => $this->captureMetrics(),
            'security' => $this->captureSecurity(),
            'resources' => $this->captureResources()
        ];
    }

    private function validateSystemMetrics(array $metrics): bool
    {
        foreach ($this->config['metric_thresholds'] as $metric => $threshold) {
            if (($metrics[$metric] ?? 0) > $threshold) {
                return false;
            }
        }
        return true;
    }

    private function validateSystemSecurity(array $security): bool
    {
        return $this->security->validateSystemSecurity($security);
    }

    private function validateSystemResources(array $resources): bool
    {
        foreach ($this->config['resource_limits'] as $resource => $limit) {
            if (($resources[$resource] ?? 0) > $limit) {
                return false;
            }
        }
        return true;
    }

    private function captureMetrics(): array
    {
        return $this->metrics->getSystemMetrics();
    }

    private function captureSecurity(): array
    {
        return $this->security->getSecurityState();
    }

    private function captureResources(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'disk' => disk_free_space('/'),
            'connections' => $this->getActiveConnections()
        ];
    }

    private function getActiveConnections(): int
    {
        // Implementation depends on server configuration
        return 0;
    }
}
