<?php

namespace App\Core\Monitoring\Health;

class HealthCheckManager {
    private array $checks;
    private StatusAggregator $aggregator;
    private MetricsCollector $metricsCollector;
    private AlertDispatcher $alertDispatcher;
    private ResultCache $cache;

    public function __construct(
        array $checks,
        StatusAggregator $aggregator,
        MetricsCollector $metricsCollector,
        AlertDispatcher $alertDispatcher,
        ResultCache $cache
    ) {
        $this->checks = $checks;
        $this->aggregator = $aggregator;
        $this->metricsCollector = $metricsCollector;
        $this->alertDispatcher = $alertDispatcher;
        $this->cache = $cache;
    }

    public function performHealthCheck(): HealthReport 
    {
        $checkResults = [];
        $startTime = microtime(true);

        foreach ($this->checks as $check) {
            try {
                $result = $check->execute();
                $checkResults[$check->getName()] = $result;

                if (!$result->isHealthy()) {
                    $this->handleUnhealthyResult($check, $result);
                }
            } catch (\Throwable $e) {
                $checkResults[$check->getName()] = new HealthCheckResult(false, $e->getMessage());
            }
        }

        $status = $this->aggregator->aggregate($checkResults);
        $duration = microtime(true) - $startTime;

        $report = new HealthReport($status, $checkResults, $duration);
        $this->cache->store($report);
        $this->metricsCollector->record($report);

        return $report;
    }

    private function handleUnhealthyResult(HealthCheck $check, HealthCheckResult $result): void 
    {
        $this->alertDispatcher->dispatch(
            new HealthAlert($check, $result)
        );
    }
}

abstract class HealthCheck {
    protected string $name;
    protected array $config;
    protected array $dependencies;

    public function getName(): string 
    {
        return $this->name;
    }

    abstract public function execute(): HealthCheckResult;
    abstract public function getDescription(): string;
}

class DatabaseHealthCheck extends HealthCheck {
    private DatabaseConnection $connection;
    private QueryBuilder $queryBuilder;

    public function execute(): HealthCheckResult 
    {
        try {
            $startTime = microtime(true);
            $query = $this->queryBuilder->buildHealthCheckQuery();
            $result = $this->connection->execute($query);
            $duration = microtime(true) - $startTime;

            return new HealthCheckResult(
                true,
                'Database is responding normally',
                [
                    'duration' => $duration,
                    'connected' => true,
                    'latency' => $duration * 1000
                ]
            );
        } catch (\Exception $e) {
            return new HealthCheckResult(
                false,
                $e->getMessage(),
                ['connected' => false]
            );
        }
    }

    public function getDescription(): string 
    {
        return 'Verifies database connectivity and response time';
    }
}

class CacheHealthCheck extends HealthCheck {
    private CacheInterface $cache;

    public function execute(): HealthCheckResult 
    {
        try {
            $key = 'health_check_' . uniqid();
            $value = microtime(true);

            $writeStart = microtime(true);
            $this->cache->set($key, $value, 60);
            $writeDuration = microtime(true) - $writeStart;

            $readStart = microtime(true);
            $retrieved = $this->cache->get($key);
            $readDuration = microtime(true) - $readStart;

            $isValid = $retrieved === $value;

            return new HealthCheckResult(
                $isValid,
                $isValid ? 'Cache is functioning normally' : 'Cache data validation failed',
                [
                    'write_duration' => $writeDuration * 1000,
                    'read_duration' => $readDuration * 1000,
                    'data_valid' => $isValid
                ]
            );
        } catch (\Exception $e) {
            return new HealthCheckResult(
                false,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }

    public function getDescription(): string 
    {
        return 'Validates cache read/write operations and timing';
    }
}

class QueueHealthCheck extends HealthCheck {
    private QueueManager $queueManager;

    public function execute(): HealthCheckResult 
    {
        try {
            $metrics = $this->queueManager->getMetrics();
            $isHealthy = $this->evaluateMetrics($metrics);

            return new HealthCheckResult(
                $isHealthy,
                $isHealthy ? 'Queue system is operating normally' : 'Queue system shows performance issues',
                [
                    'queue_length' => $metrics['length'],
                    'processing_rate' => $metrics['processing_rate'],
                    'error_rate' => $metrics['error_rate'],
                    'latency' => $metrics['latency']
                ]
            );
        } catch (\Exception $e) {
            return new HealthCheckResult(
                false,
                $e->getMessage()
            );
        }
    }

    private function evaluateMetrics(array $metrics): bool 
    {
        return $metrics['error_rate'] < 0.05 && 
               $metrics['latency'] < 1000 && 
               $metrics['processing_rate'] > 0;
    }

    public function getDescription(): string 
    {
        return 'Monitors queue system health and performance metrics';
    }
}

class FileSystemHealthCheck extends HealthCheck {
    private string $basePath;
    private array $requiredPaths;

    public function execute(): HealthCheckResult 
    {
        $issues = [];
        $details = [];

        foreach ($this->requiredPaths as $path => $permissions) {
            $fullPath = $this->basePath . '/' . $path;
            
            if (!file_exists($fullPath)) {
                $issues[] = "Path does not exist: {$path}";
                continue;
            }

            $actualPermissions = fileperms($fullPath) & 0777;
            if ($actualPermissions !== $permissions) {
                $issues[] = "Invalid permissions for {$path}: expected {$permissions}, got {$actualPermissions}";
            }

            $details[$path] = [
                'exists' => true,
                'permissions' => $actualPermissions,
                'writable' => is_writable($fullPath),
                'free_space' => disk_free_space($fullPath)
            ];
        }

        return new HealthCheckResult(
            empty($issues),
            empty($issues) ? 'File system check passed' : implode('; ', $issues),
            $details
        );
    }

    public function getDescription(): string 
    {
        return 'Verifies file system accessibility and permissions';
    }
}

class HealthCheckResult {
    private bool $healthy;
    private string $message;
    private array $details;
    private float $timestamp;

    public function __construct(bool $healthy, string $message, array $details = []) 
    {
        $this->healthy = $healthy;
        $this->message = $message;
        $this->details = $details;
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

    public function getDetails(): array 
    {
        return $this->details;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}

class HealthReport {
    private string $status;
    private array $results;
    private float $duration;
    private float $timestamp;

    public function __construct(string $status, array $results, float $duration) 
    {
        $this->status = $status;
        $this->results = $results;
        $this->duration = $duration;
        $this->timestamp = microtime(true);
    }

    public function getStatus(): string 
    {
        return $this->status;
    }

    public function getResults(): array 
    {
        return $this->results;
    }

    public function toArray(): array 
    {
        return [
            'status' => $this->status,
            'timestamp' => $this->timestamp,
            'duration' => $this->duration,
            'checks' => array_map(function ($result) {
                return [
                    'healthy' => $result->isHealthy(),
                    'message' => $result->getMessage(),
                    'details' => $result->getDetails()
                ];
            }, $this->results)
        ];
    }
}

class StatusAggregator {
    public function aggregate(array $results): string 
    {
        $hasWarning = false;
        
        foreach ($results as $result) {
            if (!$result->isHealthy()) {
                $critical = $this->isCriticalCheck($result);
                if ($critical) {
                    return 'critical';
                }
                $hasWarning = true;
            }
        }

        return $hasWarning ? 'warning' : 'healthy';
    }

    private function isCriticalCheck(HealthCheckResult $result): bool 
    {
        return isset($result->getDetails()['critical']) && 
               $result->getDetails()['critical'] === true;
    }
}

class HealthAlert {
    private HealthCheck $check;
    private HealthCheckResult $result;
    private float $timestamp;

    public function __construct(HealthCheck $check, HealthCheckResult $result) 
    {
        $this->check = $check;
        $this->result = $result;
        $this->timestamp = microtime(true);
    }

    public function getCheck(): HealthCheck 
    {
        return $this->check;
    }

    public function getResult(): HealthCheckResult 
    {
        return $this->result;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }

    public function toArray(): array 
    {
        return [
            'check' => $this->check->getName(),
            'description' => $this->check->getDescription(),
            'result' => [
                'healthy' => $this->result->isHealthy(),
                'message' => $this->result->getMessage(),
                'details' => $this->result->getDetails()
            ],
            'timestamp' => $this->timestamp
        ];
    }
}
