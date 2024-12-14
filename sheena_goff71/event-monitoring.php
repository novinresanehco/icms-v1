<?php

namespace App\Core\Events\Monitoring;

class EventMonitoringSystem
{
    private MetricsCollector $metrics;
    private AlertManager $alertManager;
    private HealthChecker $healthChecker;
    private array $thresholds;

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alertManager,
        HealthChecker $healthChecker,
        array $thresholds
    ) {
        $this->metrics = $metrics;
        $this->alertManager = $alertManager;
        $this->healthChecker = $healthChecker;
        $this->thresholds = $thresholds;
    }

    public function monitorEventProcessing(Event $event, float $duration): void
    {
        $eventType = get_class($event);
        
        $this->metrics->timing("event_processing.duration", $duration, [
            'event_type' => $eventType
        ]);

        if ($duration > ($this->thresholds['processing_time'] ?? 1.0)) {
            $this->alertManager->sendAlert(
                'slow_event_processing',
                "Event processing exceeded threshold: {$duration}s",
                ['event_type' => $eventType]
            );
        }

        $this->healthChecker->recordEventProcessing($event, $duration);
    }

    public function monitorEventQueue(string $queueName): void
    {
        $queueSize = $this->getQueueSize($queueName);
        $this->metrics->gauge("event_queue.size", $queueSize, [
            'queue' => $queueName
        ]);

        if ($queueSize > ($this->thresholds['queue_size'] ?? 1000)) {
            $this->alertManager->sendAlert(
                'queue_size_exceeded',
                "Queue size exceeded threshold: {$queueSize}",
                ['queue' => $queueName]
            );
        }
    }

    private function getQueueSize(string $queueName): int
    {
        // Implementation to get queue size
        return 0;
    }
}

class HealthChecker
{
    private array $healthMetrics = [];
    private HealthStore $store;

    public function __construct(HealthStore $store)
    {
        $this->store = $store;
    }

    public function recordEventProcessing(Event $event, float $duration): void
    {
        $eventType = get_class($event);
        
        if (!isset($this->healthMetrics[$eventType])) {
            $this->healthMetrics[$eventType] = [
                'total_processed' => 0,
                'total_duration' => 0,
                'failures' => 0,
                'last_processed' => null
            ];
        }

        $this->healthMetrics[$eventType]['total_processed']++;
        $this->healthMetrics[$eventType]['total_duration'] += $duration;
        $this->healthMetrics[$eventType]['last_processed'] = time();

        $this->store->saveMetrics($eventType, $this->healthMetrics[$eventType]);
    }

    public function checkHealth(): HealthStatus
    {
        $status = new HealthStatus();

        foreach ($this->healthMetrics as $eventType => $metrics) {
            $status->addMetric($eventType, [
                'average_duration' => $metrics['total_duration'] / $metrics['total_processed'],
                'total_processed' => $metrics['total_processed'],
                'failure_rate' => $metrics['failures'] / $metrics['total_processed'],
                'last_processed' => $metrics['last_processed']
            ]);
        }

        return $status;
    }
}

class HealthStatus
{
    private array $metrics = [];
    private string $status = 'healthy';
    private array $issues = [];

    public function addMetric(string $type, array $data): void
    {
        $this->metrics[$type] = $data;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function addIssue(string $issue): void
    {
        $this->issues[] = $issue;
    }

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getIssues(): array
    {
        return $this->issues;
    }
}

class HealthStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function saveMetrics(string $eventType, array $metrics): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO health_metrics (event_type, metrics, created_at) 
             VALUES (?, ?, ?)'
        );

        $stmt->execute([
            $eventType,
            json_encode($metrics),
            date('Y-m-d H:i:s')
        ]);
    }

    public function getMetrics(string $eventType, \DateTimeInterface $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT metrics FROM health_metrics 
             WHERE event_type = ? AND created_at >= ? 
             ORDER BY created_at DESC'
        );

        $stmt->execute([
            $eventType,
            $since->format('Y-m-d H:i:s')
        ]);

        return array_map(
            fn($row) => json_decode($row['metrics'], true),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }
}

class MonitoringDashboard
{
    private EventMonitoringSystem $monitor;
    private HealthChecker $healthChecker;
    
    public function __construct(
        EventMonitoringSystem $monitor,
        HealthChecker $healthChecker
    ) {
        $this->monitor = $monitor;
        $this->healthChecker = $healthChecker;
    }

    public function getSystemStatus(): array
    {
        $health = $this->healthChecker->checkHealth();
        
        return [
            'status' => $health->isHealthy() ? 'healthy' : 'unhealthy',
            'metrics' => $health->getMetrics(),
            'issues' => $health->getIssues(),
            'last_check' => date('Y-m-d H:i:s')
        ];
    }

    public function getEventMetrics(string $eventType, \DateTimeInterface $since): array
    {
        return [
            'processing_times' => $this->getProcessingTimes($eventType, $since),
            'error_rates' => $this->getErrorRates($eventType, $since),
            'throughput' => $this->getThroughput($eventType, $since)
        ];
    }

    private function getProcessingTimes(string $eventType, \DateTimeInterface $since): array
    {
        // Implementation to get processing times
        return [];
    }

    private function getErrorRates(string $eventType, \DateTimeInterface $since): array
    {
        // Implementation to get error rates
        return [];
    }

    private function getThroughput(string $eventType, \DateTimeInterface $since): array
    {
        // Implementation to get throughput
        return [];
    }
}

class MonitoringAlertConfiguration
{
    private array $alertRules = [];
    private array $notificationChannels = [];

    public function addAlertRule(string $metricName, string $condition, $threshold): void
    {
        $this->alertRules[$metricName] = [
            'condition' => $condition,
            'threshold' => $threshold
        ];
    }

    public function addNotificationChannel(string $channel, array $config): void
    {
        $this->notificationChannels[$channel] = $config;
    }

    public function getAlertRules(): array
    {
        return $this->alertRules;
    }

    public function getNotificationChannels(): array
    {
        return $this->notificationChannels;
    }
}

class MonitoringAlert
{
    private string $type;
    private string $message;
    private array $context;
    private int $timestamp;
    private string $severity;

    public function __construct(
        string $type,
        string $message,
        array $context = [],
        string $severity = 'warning'
    ) {
        $this->type = $type;
        $this->message = $message;
        $this->context = $context;
        $this->timestamp = time();
        $this->severity = $severity;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }
}
