<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{DB, Cache, Log};

class MonitoringSystem
{
    private MetricsCollector $metrics;
    private HealthChecker $health;
    private ErrorHandler $errors;
    private AlertManager $alerts;
    private PerformanceMonitor $performance;

    public function __construct(
        MetricsCollector $metrics,
        HealthChecker $health,
        ErrorHandler $errors,
        AlertManager $alerts,
        PerformanceMonitor $performance
    ) {
        $this->metrics = $metrics;
        $this->health = $health;
        $this->errors = $errors;
        $this->alerts = $alerts;
        $this->performance = $performance;
    }

    public function track(string $operation, callable $callback): mixed
    {
        $start = microtime(true);
        $context = $this->createContext($operation);

        try {
            $this->metrics->startOperation($context);
            $result = $callback();
            $this->metrics->endOperation($context);
            
            $this->performance->recordMetrics(
                $context,
                microtime(true) - $start
            );
            
            return $result;
        } catch (\Throwable $e) {
            $this->handleError($e, $context);
            throw $e;
        }
    }

    public function checkSystemHealth(): SystemStatus
    {
        return $this->track('health_check', function() {
            $checks = [
                'database' => $this->health->checkDatabase(),
                'cache' => $this->health->checkCache(),
                'storage' => $this->health->checkStorage(),
                'services' => $this->health->checkServices()
            ];

            $status = new SystemStatus($checks);
            
            if (!$status->isHealthy()) {
                $this->alerts->systemUnhealthy($status);
            }

            return $status;
        });
    }

    private function createContext(string $operation): MonitoringContext
    {
        return new MonitoringContext(
            $operation,
            uniqid('op_', true),
            now()
        );
    }

    private function handleError(\Throwable $e, MonitoringContext $context): void
    {
        $this->errors->handle($e, $context);
        $this->alerts->criticalError($e, $context);
        $this->metrics->recordError($context, $e);
    }
}

class HealthChecker
{
    private array $config;

    public function checkDatabase(): ComponentStatus
    {
        try {
            DB::select('SELECT 1');
            return ComponentStatus::healthy('Database operational');
        } catch (\Exception $e) {
            return ComponentStatus::unhealthy('Database error: ' . $e->getMessage());
        }
    }

    public function checkCache(): ComponentStatus
    {
        try {
            Cache::store()->ping();
            return ComponentStatus::healthy('Cache operational');
        } catch (\Exception $e) {
            return ComponentStatus::unhealthy('Cache error: ' . $e->getMessage());
        }
    }

    public function checkStorage(): ComponentStatus
    {
        $path = storage_path('app');
        if (!is_writable($path)) {
            return ComponentStatus::unhealthy('Storage not writable');
        }
        return ComponentStatus::healthy('Storage operational');
    }

    public function checkServices(): ComponentStatus
    {
        $services = $this->config['monitored_services'] ?? [];
        $failures = [];

        foreach ($services as $service) {
            try {
                if (!$this->pingService($service)) {
                    $failures[] = $service;
                }
            } catch (\Exception $e) {
                $failures[] = $service;
            }
        }

        return empty($failures)
            ? ComponentStatus::healthy('All services operational')
            : ComponentStatus::unhealthy('Service failures: ' . implode(', ', $failures));
    }

    private function pingService(string $service): bool
    {
        // Implement service-specific health check
        return true;
    }
}

class MetricsCollector
{
    private array $metrics = [];

    public function startOperation(MonitoringContext $context): void
    {
        $this->metrics[$context->id] = [
            'operation' => $context->operation,
            'start_time' => $context->timestamp,
            'metrics' => []
        ];
    }

    public function endOperation(MonitoringContext $context): void
    {
        if (isset($this->metrics[$context->id])) {
            $this->metrics[$context->id]['end_time'] = now();
        }
    }

    public function recordMetric(string $key, $value, ?string $operationId = null): void
    {
        if ($operationId && isset($this->metrics[$operationId])) {
            $this->metrics[$operationId]['metrics'][$key] = $value;
        }
    }

    public function recordError(MonitoringContext $context, \Throwable $error): void
    {
        if (isset($this->metrics[$context->id])) {
            $this->metrics[$context->id]['error'] = [
                'type' => get_class($error),
                'message' => $error->getMessage(),
                'time' => now()
            ];
        }
    }

    public function getMetrics(?string $operationId = null): array
    {
        if ($operationId) {
            return $this->metrics[$operationId] ?? [];
        }
        return $this->metrics;
    }
}

class PerformanceMonitor
{
    private array $thresholds;
    private AlertManager $alerts;

    public function recordMetrics(MonitoringContext $context, float $duration): void
    {
        $threshold = $this->thresholds[$context->operation] ?? null;
        
        if ($threshold && $duration > $threshold) {
            $this->alerts->performanceThresholdExceeded(
                $context,
                $duration,
                $threshold
            );
        }

        $this->logPerformanceMetric($context, $duration);
    }

    private function logPerformanceMetric(MonitoringContext $context, float $duration): void
    {
        Log::channel('performance')->info('Operation performance', [
            'operation' => $context->operation,
            'duration' => $duration,
            'timestamp' => $context->timestamp,
            'context' => $context->toArray()
        ]);
    }
}

class AlertManager
{
    private array $config;
    private NotificationService $notifications;

    public function criticalError(\Throwable $error, MonitoringContext $context): void
    {
        $this->notify('critical_error', [
            'error' => $error->getMessage(),
            'context' => $context->toArray(),
            'trace' => $error->getTraceAsString()
        ]);
    }

    public function systemUnhealthy(SystemStatus $status): void
    {
        $this->notify('system_unhealthy', [
            'status' => $status->toArray(),
            'timestamp' => now()
        ]);
    }

    public function performanceThresholdExceeded(
        MonitoringContext $context,
        float $duration,
        float $threshold
    ): void {
        $this->notify('performance_alert', [
            'operation' => $context->operation,
            'duration' => $duration,
            'threshold' => $threshold,
            'context' => $context->toArray()
        ]);
    }

    private function notify(string $type, array $data): void
    {
        if (isset($this->config['alerts'][$type])) {
            $this->notifications->send(
                $this->config['alerts'][$type],
                $data
            );
        }
    }
}

class ComponentStatus
{
    public function __construct(
        public readonly bool $healthy,
        public readonly string $message
    ) {}

    public static function healthy(string $message): self
    {
        return new self(true, $message);
    }

    public static function unhealthy(string $message): self
    {
        return new self(false, $message);
    }
}

class SystemStatus
{
    private array $componentStatus;

    public function __construct(array $componentStatus)
    {
        $this->componentStatus = $componentStatus;
    }

    public function isHealthy(): bool
    {
        return empty(array_filter(
            $this->componentStatus,
            fn($status) => !$status->healthy
        ));
    }

    public function toArray(): array
    {
        return array_map(
            fn($status) => [
                'healthy' => $status->healthy,
                'message' => $status->message
            ],
            $this->componentStatus
        );
    }
}

class MonitoringContext
{
    public function __construct(
        public readonly string $operation,
        public readonly string $id,
        public readonly \DateTimeInterface $timestamp
    ) {}

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'id' => $this->id,
            'timestamp' => $this->timestamp
        ];
    }
}
