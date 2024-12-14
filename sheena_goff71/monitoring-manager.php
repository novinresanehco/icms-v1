namespace App\Core\Monitoring;

class MonitoringManager implements MonitoringInterface 
{
    private MetricsRepository $metrics;
    private AlertManager $alerts;
    private SecurityManager $security;
    private PerformanceAnalyzer $analyzer;
    private SystemHealthChecker $health;
    private array $config;

    public function trackOperation(string $operation, callable $callback): mixed 
    {
        $context = $this->createContext($operation);
        $startTime = microtime(true);
        
        try {
            $this->recordOperationStart($context);
            $result = $callback();
            $this->recordOperationSuccess($context, microtime(true) - $startTime);
            return $result;
        } catch (\Throwable $e) {
            $this->recordOperationFailure($context, $e, microtime(true) - $startTime);
            throw $e;
        }
    }

    public function recordMetric(string $name, float $value, array $tags = []): void 
    {
        $this->security->executeCriticalOperation(
            new RecordMetricOperation($name, $value, $tags),
            function() use ($name, $value, $tags) {
                $metric = [
                    'name' => $name,
                    'value' => $value,
                    'tags' => $tags,
                    'timestamp' => microtime(true),
                    'metadata' => $this->getMetricMetadata()
                ];

                $this->metrics->store($metric);
                $this->analyzer->analyzeMetric($metric);
                $this->checkThresholds($metric);
            }
        );
    }

    public function recordEvent(MonitoringEvent $event): void 
    {
        $this->security->executeCriticalOperation(
            new RecordEventOperation($event),
            function() use ($event) {
                $data = [
                    'type' => $event->getType(),
                    'severity' => $event->getSeverity(),
                    'details' => $event->getDetails(),
                    'timestamp' => microtime(true),
                    'metadata' => $this->getEventMetadata($event)
                ];

                $this->metrics->storeEvent($data);
                $this->analyzer->analyzeEvent($data);
                $this->processEventTriggers($data);
            }
        );
    }

    public function checkSystemHealth(): HealthStatus 
    {
        return $this->security->executeCriticalOperation(
            new SystemHealthCheckOperation(),
            function() {
                $checks = [
                    'memory' => $this->health->checkMemoryUsage(),
                    'cpu' => $this->health->checkCpuUsage(),
                    'disk' => $this->health->checkDiskSpace(),
                    'database' => $this->health->checkDatabaseHealth(),
                    'cache' => $this->health->checkCacheHealth(),
                    'queue' => $this->health->checkQueueHealth()
                ];

                $status = $this->determineOverallHealth($checks);
                $this->recordHealthStatus($status);
                return $status;
            }
        );
    }

    protected function createContext(string $operation): MonitoringContext 
    {
        return new MonitoringContext([
            'operation' => $operation,
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'timestamp' => microtime(true)
        ]);
    }

    protected function recordOperationStart(MonitoringContext $context): void 
    {
        $this->metrics->storeOperationStart([
            'context' => $context->toArray(),
            'memory_start' => memory_get_usage(true),
            'time_start' => microtime(true)
        ]);
    }

    protected function recordOperationSuccess(MonitoringContext $context, float $duration): void 
    {
        $data = [
            'context' => $context->toArray(),
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'status' => 'success'
        ];

        $this->metrics->storeOperationEnd($data);
        $this->analyzer->analyzeOperation($data);
        $this->checkPerformanceThresholds($data);
    }

    protected function recordOperationFailure(MonitoringContext $context, \Throwable $e, float $duration): void 
    {
        $data = [
            'context' => $context->toArray(),
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'status' => 'failure',
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];

        $this->metrics->storeOperationEnd($data);
        $this->analyzer->analyzeFailure($data);
        $this->alerts->handleOperationFailure($data);
    }

    protected function checkThresholds(array $metric): void 
    {
        $thresholds = $this->config['thresholds'][$metric['name']] ?? null;
        
        if (!$thresholds) {
            return;
        }

        if ($metric['value'] > $thresholds['critical']) {
            $this->alerts->triggerCriticalAlert($metric);
        } elseif ($metric['value'] > $thresholds['warning']) {
            $this->alerts->triggerWarningAlert($metric);
        }
    }

    protected function processEventTriggers(array $event): void 
    {
        foreach ($this->config['event_triggers'] as $trigger) {
            if ($trigger->matches($event)) {
                $trigger->execute($event);
            }
        }
    }

    protected function determineOverallHealth(array $checks): HealthStatus 
    {
        $critical = 0;
        $warning = 0;

        foreach ($checks as $check) {
            if ($check->isCritical()) {
                $critical++;
            } elseif ($check->isWarning()) {
                $warning++;
            }
        }

        return new HealthStatus(
            $critical === 0 ? ($warning === 0 ? 'healthy' : 'warning') : 'critical',
            $checks
        );
    }

    protected function recordHealthStatus(HealthStatus $status): void 
    {
        $this->metrics->storeHealthStatus([
            'status' => $status->getStatus(),
            'checks' => $status->getChecks(),
            'timestamp' => microtime(true)
        ]);
    }

    protected function getMetricMetadata(): array 
    {
        return [
            'environment' => app()->environment(),
            'server' => gethostname(),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true)
        ];
    }

    protected function getEventMetadata(MonitoringEvent $event): array 
    {
        return array_merge(
            $event->getMetadata(),
            [
                'server_load' => sys_getloadavg(),
                'memory_usage' => memory_get_usage(true),
                'process_id' => getmypid()
            ]
        );
    }
}
