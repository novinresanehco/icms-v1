<?php

namespace App\Core\Monitoring;

use App\Core\Contracts\{MonitoringInterface, EventInterface};
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Log, Cache, Event};
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Core\Exceptions\{MonitoringException, EventException};

class MonitoringSystem implements MonitoringInterface
{
    private SecurityManager $security;
    private array $metrics = [];
    private array $thresholds;
    private array $alerts = [];

    public function __construct(SecurityManager $security, array $config = [])
    {
        $this->security = $security;
        $this->thresholds = $config['thresholds'] ?? [];
        $this->initializeMetrics();
    }

    public function track(string $operationId, callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $this->security->executeSecureOperation(
                $operation,
                ['operation_id' => $operationId]
            );

            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->recordMetrics($operationId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'status' => 'error',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function recordMetric(string $key, $value, array $tags = []): void
    {
        $metric = [
            'value' => $value,
            'timestamp' => microtime(true),
            'tags' => $tags
        ];

        $this->metrics[$key][] = $metric;
        $this->checkThresholds($key, $value, $tags);
        $this->persistMetric($key, $metric);
    }

    public function getMetrics(string $key = null, array $filters = []): array
    {
        if ($key !== null) {
            return $this->filterMetrics(
                $this->metrics[$key] ?? [],
                $filters
            );
        }

        $result = [];
        foreach ($this->metrics as $metricKey => $metrics) {
            $result[$metricKey] = $this->filterMetrics($metrics, $filters);
        }
        return $result;
    }

    public function alert(string $type, string $message, array $context = []): void
    {
        $alert = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        $this->alerts[] = $alert;
        $this->processAlert($alert);
    }

    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'response_time' => [],
            'memory_usage' => [],
            'error_rate' => [],
            'throughput' => []
        ];
    }

    protected function checkThresholds(string $key, $value, array $tags): void
    {
        if (!isset($this->thresholds[$key])) {
            return;
        }

        $threshold = $this->thresholds[$key];
        if ($this->isThresholdViolated($value, $threshold)) {
            $this->alert('threshold_violation', "Threshold violated for {$key}", [
                'value' => $value,
                'threshold' => $threshold,
                'tags' => $tags
            ]);
        }
    }

    protected function isThresholdViolated($value, $threshold): bool
    {
        if (is_array($threshold)) {
            return $value < ($threshold['min'] ?? PHP_FLOAT_MIN) || 
                   $value > ($threshold['max'] ?? PHP_FLOAT_MAX);
        }

        return $value > $threshold;
    }

    protected function filterMetrics(array $metrics, array $filters): array
    {
        return array_filter($metrics, function($metric) use ($filters) {
            foreach ($filters as $key => $value) {
                if (!isset($metric['tags'][$key]) || $metric['tags'][$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    protected function persistMetric(string $key, array $metric): void
    {
        $cacheKey = "metrics:{$key}:" . date('Y-m-d-H');
        Cache::tags(['metrics', $key])->put(
            $cacheKey,
            array_merge(
                Cache::tags(['metrics', $key])->get($cacheKey, []),
                [$metric]
            ),
            3600
        );
    }

    protected function processAlert(array $alert): void
    {
        Log::channel('alerts')->warning($alert['message'], $alert['context']);
        Event::dispatch('monitoring.alert', [$alert]);

        if ($alert['type'] === 'critical') {
            $this->handleCriticalAlert($alert);
        }
    }

    protected function handleCriticalAlert(array $alert): void
    {
        // Implement critical alert handling
        // This could include immediate notifications, automated responses, etc.
    }
}

class EventManager implements EventInterface
{
    private SecurityManager $security;
    private MonitoringSystem $monitor;
    private array $handlers = [];
    private array $queued = [];

    public function __construct(
        SecurityManager $security,
        MonitoringSystem $monitor
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function dispatch(string $event, array $payload = []): void
    {
        $this->security->executeSecureOperation(
            function() use ($event, $payload) {
                $this->processEvent($event, $payload);
            },
            ['action' => 'dispatch_event', 'event' => $event]
        );
    }

    public function listen(string $event, callable $handler, bool $queue = false): void
    {
        if ($queue) {
            $this->queued[$event][] = $handler;
        } else {
            $this->handlers[$event][] = $handler;
        }
    }

    public function subscribe(string $event, string $subscriber): void
    {
        if (!class_exists($subscriber) || !is_subclass_of($subscriber, ShouldQueue::class)) {
            throw new EventException("Invalid event subscriber: {$subscriber}");
        }

        $this->queued[$event][] = [$subscriber, 'handle'];
    }

    protected function processEvent(string $event, array $payload): void
    {
        $startTime = microtime(true);

        try {
            // Process synchronous handlers
            foreach ($this->handlers[$event] ?? [] as $handler) {
                $handler($payload);
            }

            // Queue asynchronous handlers
            foreach ($this->queued[$event] ?? [] as $handler) {
                if (is_array($handler) && is_string($handler[0])) {
                    $subscriber = new $handler[0];
                    $subscriber->handle($payload);
                } else {
                    $handler($payload);
                }
            }

            $this->monitor->recordMetric('event_processing', [
                'event' => $event,
                'duration' => microtime(true) - $startTime,
                'status' => 'success'
            ]);

        } catch (\Throwable $e) {
            $this->monitor->recordMetric('event_processing', [
                'event' => $event,
                'duration' => microtime(true) - $startTime,
                'status' => 'error',
                'error' => $e->getMessage()
            ]);

            throw new EventException(
                "Event processing failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}
