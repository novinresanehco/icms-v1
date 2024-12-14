<?php

namespace App\Core\Monitoring;

class ServiceMonitor
{
    private array $checks = [];
    private ProbeManager $probeManager;
    private MetricsCollector $metricsCollector;
    private AlertManager $alertManager;

    public function addCheck(ServiceCheck $check): void
    {
        $this->checks[] = $check;
    }

    public function runChecks(): HealthReport
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($this->checks as $check) {
            try {
                $checkResult = $this->probeManager->probe($check);
                $results[$check->getName()] = $checkResult;
                
                if (!$checkResult->isHealthy()) {
                    $this->alertManager->notify($check, $checkResult);
                }
            } catch (\Exception $e) {
                $results[$check->getName()] = new HealthResult(false, $e->getMessage());
            }
        }

        $duration = microtime(true) - $startTime;
        $this->metricsCollector->record('health_check_duration', $duration);

        return new HealthReport($results);
    }

    public function getMetrics(): array
    {
        return $this->metricsCollector->getMetrics();
    }
}

abstract class ServiceCheck
{
    private string $name;
    private array $options;

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->options = $options;
    }

    abstract public function execute(): HealthResult;

    public function getName(): string
    {
        return $this->name;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

class DatabaseCheck extends ServiceCheck
{
    private $connection;
    
    public function execute(): HealthResult
    {
        try {
            $start = microtime(true);
            $this->connection->select('SELECT 1');
            $duration = microtime(true) - $start;

            return new HealthResult(
                true,
                'Database connection successful',
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new HealthResult(false, $e->getMessage());
        }
    }
}

class RedisCheck extends ServiceCheck
{
    private $redis;

    public function execute(): HealthResult
    {
        try {
            $start = microtime(true);
            $this->redis->ping();
            $duration = microtime(true) - $start;

            return new HealthResult(
                true,
                'Redis connection successful',
                ['duration' => $duration]
            );
        } catch (\Exception $e) {
            return new HealthResult(false, $e->getMessage());
        }
    }
}

class HealthResult
{
    private bool $healthy;
    private string $message;
    private array $metadata;

    public function __construct(bool $healthy, string $message, array $metadata = [])
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
    private \DateTime $timestamp;

    public function __construct(array $results)
    {
        $this->results = $results;
        $this->timestamp = new \DateTime();
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

    public function getResults(): array
    {
        return $this->results;
    }

    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'timestamp' => $this->timestamp->format('c'),
            'checks' => array_map(function ($result) {
                return [
                    'healthy' => $result->isHealthy(),
                    'message' => $result->getMessage(),
                    'metadata' => $result->getMetadata()
                ];
            }, $this->results)
        ];
    }
}

class ProbeManager
{
    private MetricsCollector $metricsCollector;
    private int $timeout;

    public function probe(ServiceCheck $check): HealthResult
    {
        $timeoutHandler = set_time_limit($this->timeout);
        
        try {
            $startTime = microtime(true);
            $result = $check->execute();
            $duration = microtime(true) - $startTime;
            
            $this->metricsCollector->record(
                "check_{$check->getName()}_duration",
                $duration
            );
            
            return $result;
        } finally {
            set_time_limit($timeoutHandler);
        }
    }
}

class AlertManager
{
    private array $channels = [];
    private array $rules = [];

    public function notify(ServiceCheck $check, HealthResult $result): void
    {
        $alert = new Alert($check, $result);
        
        foreach ($this->channels as $channel) {
            if ($this->shouldNotify($alert, $channel)) {
                $channel->send($alert);
            }
        }
    }

    private function shouldNotify(Alert $alert, AlertChannel $channel): bool
    {
        foreach ($this->rules as $rule) {
            if (!$rule->evaluate($alert, $channel)) {
                return false;
            }
        }
        return true;
    }
}

class Alert
{
    private ServiceCheck $check;
    private HealthResult $result;
    private \DateTime $timestamp;

    public function __construct(ServiceCheck $check, HealthResult $result)
    {
        $this->check = $check;
        $this->result = $result;
        $this->timestamp = new \DateTime();
    }

    public function getCheck(): ServiceCheck
    {
        return $this->check;
    }

    public function getResult(): HealthResult
    {
        return $this->result;
    }

    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }
}
