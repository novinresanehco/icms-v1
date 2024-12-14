<?php

namespace App\Core\Monitoring\Health;

class HealthMonitor
{
    private CheckRegistry $registry;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private StatusAggregator $aggregator;

    public function checkHealth(): HealthStatus
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($this->registry->getChecks() as $check) {
            try {
                $results[$check->getName()] = $check->execute();
                $this->metrics->recordCheck($check->getName(), microtime(true) - $startTime);
            } catch (\Exception $e) {
                $results[$check->getName()] = new CheckResult(false, $e->getMessage());
                $this->alerts->notify(new HealthCheckFailure($check, $e));
            }
        }

        return $this->aggregator->aggregate($results);
    }

    public function registerCheck(HealthCheck $check): void
    {
        $this->registry->register($check);
    }

    public function getMetrics(): array
    {
        return $this->metrics->getMetrics();
    }
}

class DatabaseHealthCheck implements HealthCheck
{
    private \PDO $connection;
    private QueryBuilder $queryBuilder;
    private float $timeout;

    public function execute(): CheckResult
    {
        try {
            $start = microtime(true);
            $query = $this->queryBuilder->buildHealthCheckQuery();
            
            $statement = $this->connection->prepare($query);
            $statement->execute();
            
            $duration = microtime(true) - $start;
            
            if ($duration > $this->timeout) {
                return new CheckResult(
                    false,
                    "Database response time ({$duration}s) exceeded timeout ({$this->timeout}s)"
                );
            }

            return new CheckResult(true, "Database is responsive");
        } catch (\PDOException $e) {
            return new CheckResult(false, "Database connection failed: " . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'database';
    }
}

class CacheHealthCheck implements HealthCheck
{
    private CacheInterface $cache;
    private string $testKey;
    private string $testValue;

    public function execute(): CheckResult
    {
        try {
            // Write test
            $this->cache->set($this->testKey, $this->testValue, 30);
            
            // Read test
            $value = $this->cache->get($this->testKey);
            
            if ($value !== $this->testValue) {
                return new CheckResult(false, "Cache read/write test failed");
            }
            
            // Delete test
            $this->cache->delete($this->testKey);
            
            return new CheckResult(true, "Cache is functioning properly");
        } catch (\Exception $e) {
            return new CheckResult(false, "Cache check failed: " . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'cache';
    }
}

class QueueHealthCheck implements HealthCheck
{
    private QueueManager $queueManager;
    private array $queues;

    public function execute(): CheckResult
    {
        $results = [];
        
        foreach ($this->queues as $queue) {
            try {
                $status = $this->queueManager->checkQueue($queue);
                $results[$queue] = $status;
                
                if (!$status->isHealthy()) {
                    return new CheckResult(
                        false,
                        "Queue '$queue' is unhealthy: " . $status->getMessage()
                    );
                }
            } catch (\Exception $e) {
                return new CheckResult(
                    false,
                    "Failed to check queue '$queue': " . $e->getMessage()
                );
            }
        }
        
        return new CheckResult(true, "All queues are healthy");
    }

    public function getName(): string
    {
        return 'queue';
    }
}

class DiskSpaceHealthCheck implements HealthCheck
{
    private array $paths;
    private int $warningThreshold;
    private int $criticalThreshold;

    public function execute(): CheckResult
    {
        $problems = [];
        
        foreach ($this->paths as $path) {
            $free = disk_free_space($path);
            $total = disk_total_space($path);
            $used = $total - $free;
            $usedPercentage = ($used / $total) * 100;
            
            if ($usedPercentage >= $this->criticalThreshold) {
                $problems[] = "Critical: $path is {$usedPercentage}% full";
            } elseif ($usedPercentage >= $this->warningThreshold) {
                $problems[] = "Warning: $path is {$usedPercentage}% full";
            }
        }
        
        if (!empty($problems)) {
            return new CheckResult(false, implode(", ", $problems));
        }
        
        return new CheckResult(true, "Disk space is within acceptable limits");
    }

    public function getName(): string
    {
        return 'disk_space';
    }
}

class CheckResult
{
    private bool $healthy;
    private string $message;
    private array $metadata;
    private float $timestamp;

    public function __construct(bool $healthy, string $message, array $metadata = [])
    {
        $this->healthy = $healthy;
        $this->message = $message;
        $this->metadata = $metadata;
        $this->timestamp = microtime(true);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class StatusAggregator
{
    private array $weights;

    public function aggregate(array $results): HealthStatus
    {
        $totalWeight = 0;
        $weightedScore = 0;
        $issues = [];

        foreach ($results as $name => $result) {
            $weight = $this->weights[$name] ?? 1;
            $totalWeight += $weight;
            
            if ($result->isHealthy()) {
                $weightedScore += $weight;
            } else {
                $issues[] = [
                    'check' => $name,
                    'message' => $result->getMessage(),
                    'metadata' => $result->getMetadata()
                ];
            }
        }

        $score = $totalWeight > 0 ? ($weightedScore / $totalWeight) * 100 : 0;

        return new HealthStatus($score >= 100, $score, $issues);
    }
}

class HealthStatus
{
    private bool $healthy;
    private float $score;
    private array $issues;
    private float $timestamp;

    public function __construct(bool $healthy, float $score, array $issues)
    {
        $this->healthy = $healthy;
        $this->score = $score;
        $this->issues = $issues;
        $this->timestamp = microtime(true);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getScore(): float
    {
        return $this->score;
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