<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{DB, Redis, Log};
use Carbon\Carbon;

class MonitoringSystem implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $audit;
    
    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        AuditLogger $audit
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->audit = $audit;
    }

    public function track(string $operation, callable $callback, array $context = []): mixed
    {
        $startTime = microtime(true);
        $operationId = $this->generateOperationId();

        try {
            $this->audit->logOperationStart($operationId, $operation, $context);
            $result = $callback();
            
            $duration = microtime(true) - $startTime;
            $this->recordSuccess($operationId, $operation, $duration, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $this->recordFailure($operationId, $operation, $duration, $e, $context);
            throw $e;
        }
    }

    protected function recordSuccess(string $id, string $operation, float $duration, array $context): void
    {
        $this->metrics->recordOperation([
            'id' => $id,
            'operation' => $operation,
            'duration' => $duration,
            'status' => 'success',
            'timestamp' => Carbon::now(),
            'context' => $context
        ]);

        if ($duration > $this->getThreshold($operation)) {
            $this->alerts->performanceWarning($operation, $duration, $context);
        }
    }

    protected function recordFailure(string $id, string $operation, float $duration, \Throwable $e, array $context): void
    {
        $this->metrics->recordOperation([
            'id' => $id,
            'operation' => $operation,
            'duration' => $duration,
            'status' => 'failure',
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ],
            'timestamp' => Carbon::now(),
            'context' => $context
        ]);

        $this->alerts->operationFailure($operation, $e, $context);
    }

    protected function generateOperationId(): string
    {
        return hash('sha256', uniqid('', true));
    }

    protected function getThreshold(string $operation): float
    {
        return config("monitoring.thresholds.{$operation}", 1.0);
    }
}

class MetricsCollector
{
    private const BATCH_SIZE = 100;
    private array $metrics = [];

    public function recordOperation(array $data): void
    {
        $this->metrics[] = $data;
        
        if (count($this->metrics) >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->metrics)) {
            return;
        }

        DB::transaction(function() {
            DB::table('operation_metrics')->insert($this->metrics);
            
            $this->updateAggregates($this->metrics);
            
            Redis::pipeline(function($pipe) {
                foreach ($this->metrics as $metric) {
                    $this->cacheMetric($pipe, $metric);
                }
            });
        });

        $this->metrics = [];
    }

    protected function updateAggregates(array $metrics): void
    {
        $aggregates = [];
        
        foreach ($metrics as $metric) {
            $key = $metric['operation'] . ':' . date('Y-m-d');
            
            if (!isset($aggregates[$key])) {
                $aggregates[$key] = [
                    'operation' => $metric['operation'],
                    'date' => date('Y-m-d'),
                    'count' => 0,
                    'success_count' => 0,
                    'failure_count' => 0,
                    'total_duration' => 0,
                    'max_duration' => 0
                ];
            }

            $aggregates[$key]['count']++;
            $aggregates[$key][$metric['status'] . '_count']++;
            $aggregates[$key]['total_duration'] += $metric['duration'];
            $aggregates[$key]['max_duration'] = max(
                $aggregates[$key]['max_duration'],
                $metric['duration']
            );
        }

        foreach ($aggregates as $aggregate) {
            DB::table('operation_aggregates')
                ->updateOrInsert(
                    [
                        'operation' => $aggregate['operation'],
                        'date' => $aggregate['date']
                    ],
                    $aggregate
                );
        }
    }

    protected function cacheMetric($pipe, array $metric): void
    {
        $key = "metrics:{$metric['operation']}:" . date('Y-m-d-H');
        
        $pipe->zadd($key, $metric['timestamp'], json_encode([
            'id' => $metric['id'],
            'duration' => $metric['duration'],
            'status' => $metric['status']
        ]));
        
        $pipe->expire($key, 86400 * 7);
    }
}

class AlertManager
{
    private array $handlers = [];
    private array $thresholds;

    public function __construct(array $thresholds)
    {
        $this->thresholds = $thresholds;
    }

    public function performanceWarning(string $operation, float $duration, array $context): void
    {
        $alert = new Alert(
            type: 'performance_warning',
            operation: $operation,
            severity: $this->calculateSeverity($duration, $operation),
            data: [
                'duration' => $duration,
                'threshold' => $this->thresholds[$operation] ?? null,
                'context' => $context
            ]
        );

        $this->dispatch($alert);
    }

    public function operationFailure(string $operation, \Throwable $e, array $context): void
    {
        $alert = new Alert(
            type: 'operation_failure',
            operation: $operation,
            severity: 'high',
            data: [
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'context' => $context
            ]
        );

        $this->dispatch($alert);
    }

    protected function calculateSeverity(float $duration, string $operation): string
    {
        $threshold = $this->thresholds[$operation] ?? 1.0;
        
        return match(true) {
            $duration > $threshold * 3 => 'critical',
            $duration > $threshold * 2 => 'high',
            $duration > $threshold => 'medium',
            default => 'low'
        };
    }

    protected function dispatch(Alert $alert): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($alert);
            } catch (\Exception $e) {
                Log::error('Alert handler failed', [
                    'handler' => get_class($handler),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}

class Alert
{
    public function __construct(
        public readonly string $type,
        public readonly string $operation,
        public readonly string $severity,
        public readonly array $data,
        public readonly Carbon $timestamp = new Carbon()
    ) {}
}

class AuditLogger
{
    public function logOperationStart(string $id, string $operation, array $context): void
    {
        DB::table('audit_log')->insert([
            'operation_id' => $id,
            'type' => 'operation_start',
            'operation' => $operation,
            'context' => json_encode($context),
            'timestamp' => Carbon::now()
        ]);
    }
}

interface MonitorInterface
{
    public function track(string $operation, callable $callback, array $context = []): mixed;
}
