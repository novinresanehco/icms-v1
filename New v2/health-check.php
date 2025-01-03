<?php

namespace App\Core\Health;

class HealthManager implements HealthManagerInterface
{
    private array $checks = [];
    private HealthStorage $storage;
    private AlertManager $alerts;

    public function __construct(HealthStorage $storage, AlertManager $alerts)
    {
        $this->storage = $storage;
        $this->alerts = $alerts;
    }

    public function registerCheck(string $name, HealthCheckInterface $check): void
    {
        $this->checks[$name] = $check;
    }

    public function runChecks(): HealthReport
    {
        $results = [];
        $critical = false;

        foreach ($this->checks as $name => $check) {
            try {
                $result = $check->check();
                $results[$name] = $result;
                
                if ($result->status === HealthStatus::Critical) {
                    $critical = true;
                }
            } catch (\Throwable $e) {
                $results[$name] = new HealthResult(
                    HealthStatus::Critical,
                    "Check failed: {$e->getMessage()}"
                );
                $critical = true;
            }
        }

        $report = new HealthReport($results);
        $this->storage->storeReport($report);

        if ($critical) {
            $this->alerts->criticalSystemHealth($report);
        }

        return $report;
    }
}

interface HealthCheckInterface
{
    public function check(): HealthResult;
}

class DatabaseHealthCheck implements HealthCheckInterface
{
    public function check(): HealthResult
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $duration = microtime(true) - $start;

            if ($duration > 1.0) {
                return new HealthResult(
                    HealthStatus::Warning,
                    "Database response time: {$duration}s"
                );
            }

            return new HealthResult(
                HealthStatus::Healthy,
                "Database connection successful"
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                HealthStatus::Critical,
                "Database connection failed: {$e->getMessage()}"
            );
        }
    }
}

class CacheHealthCheck implements HealthCheckInterface
{
    private Cache $cache;

    public function check(): HealthResult
    {
        try {
            $key = 'health_check_' . time();
            $value = rand();

            $this->cache->put($key, $value, 10);
            $retrieved = $this->cache->get($key);

            if ($retrieved !== $value) {
                return new HealthResult(
                    HealthStatus::Critical,
                    "Cache read/write verification failed"
                );
            }

            return new HealthResult(
                HealthStatus::Healthy,
                "Cache system operational"
            );
        } catch (\Throwable $e) {
            return new HealthResult(
                HealthStatus::Critical,
                "Cache system failed: {$e->getMessage()}"
            );
        }
    }
}

class SearchHealthCheck implements HealthCheckInterface
{
    private SearchService $search;

    public function check(): HealthResult
    {
        try {
            $start = microtime(true);
            $results = $this->search->search('health_check_query