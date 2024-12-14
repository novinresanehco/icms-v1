<?php

namespace App\Core\Health;

class SystemHealthMonitor
{
    private array $checks = [];
    private array $results = [];
    private HealthNotifier $notifier;

    public function addCheck(string $name, HealthCheck $check): void
    {
        $this->checks[$name] = $check;
    }

    public function runHealthCheck(): HealthReport
    {
        $results = [];

        foreach ($this->checks as $name => $check) {
            try {
                $results[$name] = $check->check();
            } catch (\Throwable $e) {
                $results[$name] = new HealthResult(false, $e->getMessage());
            }
        }

        $this->results = $results;
        $this->notifyIfUnhealthy($results);

        return new HealthReport($results);
    }

    private function notifyIfUnhealthy(array $results): void
    {
        $unhealthy = array_filter(
            $results,
            fn($result) => !$result->isHealthy()
        );

        if (!empty($unhealthy)) {
            $this->notifier->notifyUnhealthySystem($unhealthy);
        }
    }

    public function getLastResults(): array
    {
        return $this->results;
    }
}

abstract class HealthCheck
{
    abstract public function check(): HealthResult;
    
    protected function createResult(bool $healthy, string $message = ''): HealthResult
    {
        return new HealthResult($healthy, $message);
    }
}

class HealthResult
{
    private bool $healthy;
    private string $message;
    private array $metadata;

    public function __construct(bool $healthy, string $message = '', array $metadata = [])
    {
        $this->healthy = $healthy;
        $this->message = $message;
        $this->metadata = $metadata;
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
}

class HealthReport
{
    private array $results;
    private array $metadata;

    public function __construct(array $results)
    {
        $this->results = $results;
        $this->metadata = [
            'timestamp' => time(),
            'total_checks' => count($results),
            'healthy_checks' => count(array_filter($results, fn($r) => $r->isHealthy())),
        ];
    }

    public function isHealthy(): bool
    {
        return empty(array_filter(
            $this->results,
            fn($result) => !$result->isHealthy()
        ));
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'results' => array_map(fn($r) => [
                'healthy' => $r->isHealthy(),
                'message' => $r->getMessage(),
                'metadata' => $r->getMetadata()
            ], $this->results),
            'metadata' => $this->metadata
        ];
    }
}
