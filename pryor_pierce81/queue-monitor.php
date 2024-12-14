<?php

namespace App\Core\Monitoring\Queue;

class QueueMonitor
{
    private QueueRegistry $queueRegistry;
    private MetricsCollector $metricsCollector;
    private HealthChecker $healthChecker;
    private AlertManager $alertManager;
    private PerformanceAnalyzer $performanceAnalyzer;

    public function monitor(): QueueStatus
    {
        $metrics = [];
        $health = [];
        $performance = [];

        foreach ($this->queueRegistry->getQueues() as $queue) {
            $queueMetrics = $this->metricsCollector->collectQueueMetrics($queue);
            $queueHealth = $this->healthChecker->checkQueue($queue);
            $queuePerformance = $this->performanceAnalyzer->analyzeQueue($queue, $queueMetrics);

            $metrics[$queue->getName()] = $queueMetrics;
            $health[$queue->getName()] = $queueHealth;
            $performance[$queue->getName()] = $queuePerformance;

            if ($queueHealth->hasIssues()) {
                $this->alertManager->notifyQueueIssue($queue, $queueHealth);
            }
        }

        return new QueueStatus($metrics, $health, $performance);
    }
}

class MetricsCollector
{
    private StatsCollector $statsCollector;
    private JobTracker $jobTracker;
    private ResourceMonitor $resourceMonitor;

    public function collectQueueMetrics(Queue $queue): QueueMetrics
    {
        return new QueueMetrics([
            'stats' => $this->statsCollector->collect($queue),
            'jobs' => $this->jobTracker->getJobStats($queue),
            'resources' => $this->resourceMonitor->getQueueResources($queue)
        ]);
    }
}

class HealthChecker
{
    private ConnectionChecker $connectionChecker;
    private ConsistencyChecker $consistencyChecker;
    private StateValidator $stateValidator;

    public function checkQueue(Queue $queue): QueueHealth
    {
        $issues = [];

        try {
            $connectionStatus = $this->connectionChecker->check($queue);
            $consistencyStatus = $this->consistencyChecker->check($queue);
            $stateStatus = $this->stateValidator->validate($queue);

            if (!$connectionStatus->isHealthy()) {
                $issues[] = new HealthIssue('connection', $connectionStatus->getMessage());
            }

            if (!$consistencyStatus->isValid()) {
                $issues[] = new HealthIssue('consistency', $consistencyStatus->getMessage());
            }

            if (!$stateStatus->isValid()) {
                $issues[] = new HealthIssue('state', $stateStatus->getMessage());
            }

        } catch (\Exception $e) {
            $issues[] = new HealthIssue('check_failure', $e->getMessage());
        }

        return new QueueHealth($issues);
    }
}

class PerformanceAnalyzer
{
    private ThresholdManager $thresholdManager;
    private TrendAnalyzer $trendAnalyzer;
    private BottleneckDetector $bottleneckDetector;

    public function analyzeQueue(Queue $queue, QueueMetrics $metrics): QueuePerformance
    {
        $thresholdViolations = $this->thresholdManager->checkViolations($metrics);
        $trends = $this->trendAnalyzer->analyzeTrends($metrics);
        $bottlenecks = $this->bottleneckDetector->detectBottlenecks($queue, $metrics);

        return new QueuePerformance(
            $thresholdViolations,
            $trends,
            $bottlenecks
        );
    }
}

class QueueStatus
{
    private array $metrics;
    private array $health;
    private array $performance;
    private float $timestamp;

    public function __construct(array $metrics, array $health, array $performance)
    {
        $this->metrics = $metrics;
        $this->health = $health;
        $this->performance = $performance;
        $this->timestamp = microtime(true);
    }

    public function getQueueMetrics(string $queueName): ?QueueMetrics
    {
        return $this->metrics[$queueName] ?? null;
    }

    public function getQueueHealth(string $queueName): ?QueueHealth
    {
        return $this->health[$queueName] ?? null;
    }

    public function getQueuePerformance(string $queueName): ?QueuePerformance
    {
        return $this->performance[$queueName] ?? null;
    }

    public function hasIssues(): bool
    {
        return array_reduce($this->health, function ($carry, $health) {
            return $carry || $health->hasIssues();
        }, false);
    }
}

class QueueMetrics
{
    private array $data;
    private float $timestamp;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->timestamp = microtime(true);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getValue(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class QueueHealth
{
    private array $issues;
    private float $timestamp;

    public function __construct(array $issues)
    {
        $this->issues = $issues;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class QueuePerformance
{
    private array $thresholdViolations;
    private array $trends;
    private array $bottlenecks;
    private float $timestamp;

    public function __construct(
        array $thresholdViolations,
        array $trends,
        array $bottlenecks
    ) {
        $this->thresholdViolations = $thresholdViolations;
        $this->trends = $trends;
        $this->bottlenecks = $bottlenecks;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !empty($this->thresholdViolations) || !empty($this->bottlenecks);
    }

    public function getThresholdViolations(): array
    {
        return $this->thresholdViolations;
    }

    public function getTrends(): array
    {
        return $this->trends;
    }

    public function getBottlenecks(): array
    {
        return $this->bottlenecks;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}
