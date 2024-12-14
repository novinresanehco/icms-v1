<?php

namespace App\Core\Monitoring;

class HealthMonitoringService 
{
    private SystemMetricsCollector $metricsCollector;
    private HealthCheckRegistry $healthChecks;
    private AlertManager $alertManager;
    private LoggerInterface $logger;
    private array $thresholds;

    public function __construct(
        SystemMetricsCollector $metricsCollector,
        HealthCheckRegistry $healthChecks,
        AlertManager $alertManager,
        LoggerInterface $logger,
        array $thresholds
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->healthChecks = $healthChecks;
        $this->alertManager = $alertManager;
        $this->logger = $logger;
        $this->thresholds = $thresholds;
    }

    public function performHealthCheck(): HealthReport
    {
        $startTime = microtime(true);
        $results = [];

        foreach ($this->healthChecks->getAll() as $check) {
            try {
                $checkResult = $check->execute();
                $results[$check->getName()] = $checkResult;

                if (!$checkResult->isHealthy()) {
                    $this->handleUnhealthyCheck($check, $checkResult);
                }
            } catch (\Exception $e) {
                $results[$check->getName()] = new HealthCheckResult(false, ['error' => $e->getMessage()]);
                $this->handleCheckError($check, $e);
            }
        }

        $metrics = $this->metricsCollector->collectMetrics();
        $this->analyzeMetrics($metrics);

        $duration = microtime(true) - $startTime;
        return new HealthReport($results, $metrics, $duration);
    }

    protected function analyzeMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (isset($this->thresholds[$metric]) && $value > $this->thresholds[$metric]) {
                $this->alertManager->sendAlert(
                    AlertLevel::WARNING,
                    "Metric {$metric} exceeded threshold",
                    ['current' => $value, 'threshold' => $this->thresholds[$metric]]
                );
            }
        }
    }

    protected function handleUnhealthyCheck(HealthCheck $check, HealthCheckResult $result): void
    {
        $this->alertManager->sendAlert(
            AlertLevel::ERROR,
            "Health check failed: {$check->getName()}",
            $result->getDetails()
        );

        $this->logger->error('Health check failed', [
            'check' => $check->getName(),
            'details' => $result->getDetails()
        ]);
    }

    protected function handleCheckError(HealthCheck $check, \Exception $e): void
    {
        $this->alertManager->sendAlert(
            AlertLevel::CRITICAL,
            "Health check error: {$check->getName()}",
            ['error' => $e->getMessage()]
        );

        $this->logger->error('Health check error', [
            'check' => $check->getName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class HealthCheckRegistry
{
    private array $checks = [];

    public function register(HealthCheck $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    public function getAll(): array
    {
        return $this->checks;
    }
}

interface HealthCheck
{
    public function getName(): string;
    public function execute(): HealthCheckResult;
}

class HealthCheckResult
{
    private bool $healthy;
    private array $details;

    public function __construct(bool $healthy, array $details = [])
    {
        $this->healthy = $healthy;
        $this->details = $details;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

class HealthReport
{
    private array $results;
    private array $metrics;
    private float $duration;

    public function __construct(array $results, array $metrics, float $duration)
    {
        $this->results = $results;
        $this->metrics = $metrics;
        $this->duration = $duration;
    }

    public function isHealthy(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->isHealthy()) {
                return false;
            }
        }
        return true;
    }

    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'timestamp' => time(),
            'duration' => $this->duration,
            'results' => array_map(function ($result) {
                return [
                    'healthy' => $result->isHealthy(),
                    'details' => $result->getDetails()
                ];
            }, $this->results),
            'metrics' => $this->metrics
        ];
    }
}

class DatabaseHealthCheck implements HealthCheck
{
    private DatabaseConnection $db;
    private float $timeout;

    public function getName(): string
    {
        return 'database';
    }

    public function execute(): HealthCheckResult
    {
        try {
            $startTime = microtime(true);
            $this->db->query('SELECT 1');
            $duration = microtime(true) - $startTime;

            return new HealthCheckResult(
                $duration <= $this->timeout,
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new HealthCheckResult(false, [
                'error' => $e->getMessage()
            ]);
        }
    }
}

class CacheHealthCheck implements HealthCheck
{
    private CacheInterface $cache;
    private float $timeout;

    public function getName(): string
    {
        return 'cache';
    }

    public function execute(): HealthCheckResult
    {
        try {
            $key = 'health_check_' . uniqid();
            $value = time();

            $startTime = microtime(true);
            $this->cache->set($key, $value, 30);
            $retrieved = $this->cache->get($key);
            $duration = microtime(true) - $startTime;

            return new HealthCheckResult(
                $duration <= $this->timeout && $retrieved === $value,
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new HealthCheckResult(false, [
                'error' => $e->getMessage()
            ]);
        }
    }
}
