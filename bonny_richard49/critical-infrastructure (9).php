// Core/Infrastructure/LoadBalancer.php
<?php

namespace App\Core\Infrastructure;

class LoadBalancer
{
    protected array $healthChecks = [];
    protected array $servers = [];
    protected MetricsCollector $metrics;
    protected AlertManager $alerts;

    public function registerServer(string $id, array $config): void
    {
        $this->validateServerConfig($config);
        $this->servers[$id] = array_merge($config, [
            'health' => 100,
            'active_connections' => 0,
            'last_check' => now()
        ]);
    }

    public function routeRequest(Request $request): string
    {
        $healthyServers = $this->getHealthyServers();
        
        if (empty($healthyServers)) {
            $this->handleNoHealthyServers();
        }

        return $this->selectOptimalServer($healthyServers, $request);
    }

    protected function validateServerConfig(array $config): void
    {
        $required = ['host', 'port', 'capacity', 'weight'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new InvalidConfigurationException("Missing required field: {$field}");
            }
        }
    }

    protected function getHealthyServers(): array
    {
        return array_filter($this->servers, function($server) {
            return $server['health'] > 70 && 
                   $server['active_connections'] < $server['capacity'];
        });
    }

    protected function handleNoHealthyServers(): void
    {
        $this->alerts->trigger('no_healthy_servers', [
            'total_servers' => count($this->servers),
            'server_states' => array_map(function($server) {
                return [
                    'health' => $server['health'],
                    'connections' => $server['active_connections']
                ];
            }, $this->servers)
        ]);

        throw new NoHealthyServersException('No healthy servers available');
    }

    protected function selectOptimalServer(array $servers, Request $request): string
    {
        $scores = [];
        
        foreach ($servers as $id => $server) {
            $scores[$id] = $this->calculateServerScore($server, $request);
        }

        arsort($scores);
        return array_key_first($scores);
    }

    protected function calculateServerScore(array $server, Request $request): float
    {
        return (
            $server['health'] * 0.4 +
            (1 - ($server['active_connections'] / $server['capacity'])) * 0.4 +
            $server['weight'] * 0.2
        );
    }
}

// Core/Infrastructure/CacheManager.php
<?php

namespace App\Core\Infrastructure;

class CacheManager
{
    protected array $stores = [];
    protected array $config;
    protected MetricsCollector $metrics;

    public function get(string $key, $default = null)
    {
        $stores = $this->getStoresForKey($key);
        
        foreach ($stores as $store) {
            if ($value = $store->get($key)) {
                $this->recordHit($store, $key);
                return $value;
            }
        }

        $this->recordMiss($key);
        return $default;
    }

    public function set(string $key, $value, $ttl = null): bool
    {
        $stores = $this->getStoresForKey($key);
        $success = true;
        
        foreach ($stores as $store) {
            if (!$store->set($key, $value, $ttl)) {
                $success = false;
                $this->handleStorageFailure($store, $key);
            }
        }

        return $success;
    }

    protected function getStoresForKey(string $key): array
    {
        return array_filter($this->stores, function($store) use ($key) {
            return $store->accepts($key);
        });
    }

    protected function recordHit(CacheStore $store, string $key): void
    {
        $this->metrics->increment('cache.hits', [
            'store' => $store->getName(),
            'key_pattern' => $this->getKeyPattern($key)
        ]);
    }

    protected function recordMiss(string $key): void
    {
        $this->metrics->increment('cache.misses', [
            'key_pattern' => $this->getKeyPattern($key)
        ]);
    }

    protected function handleStorageFailure(CacheStore $store, string $key): void
    {
        $this->metrics->increment('cache.failures', [
            'store' => $store->getName(),
            'key_pattern' => $this->getKeyPattern($key)
        ]);

        Log::error('Cache storage failure', [
            'store' => $store->getName(),
            'key' => $key,
            'timestamp' => now()
        ]);
    }

    protected function getKeyPattern(string $key): string
    {
        return preg_replace('/[0-9]+/', '*', $key);
    }
}

// Core/Infrastructure/QueueManager.php
<?php

namespace App\Core\Infrastructure;

class QueueManager
{
    protected array $queues;
    protected array $workers;
    protected MetricsCollector $metrics;
    protected AlertManager $alerts;

    public function push(string $queue, $job): void
    {
        $this->validateQueue($queue);
        
        if (!$this->queues[$queue]->push($job)) {
            $this->handlePushFailure($queue, $job);
        }

        $this->metrics->increment('queue.pushed', ['queue' => $queue]);
    }

    public function process(string $queue): void
    {
        $this->validateQueue($queue);
        
        while ($job = $this->queues[$queue]->pop()) {
            try {
                $this->processJob($job);
                $this->metrics->increment('queue.processed', ['queue' => $queue]);
            } catch (\Throwable $e) {
                $this->handleJobFailure($queue, $job, $e);
            }
        }
    }

    protected function processJob($job): void
    {
        $startTime = microtime(true);
        
        try {
            $job->handle();
            $this->recordJobSuccess($job, $startTime);
        } catch (\Throwable $e) {
            $this->recordJobFailure($job, $e, $startTime);
            throw $e;
        }
    }

    protected function handleJobFailure(string $queue, $job, \Throwable $e): void
    {
        Log::error('Queue job failed', [
            'queue' => $queue,
            'job' => get_class($job),
            'error' => $e->getMessage()
        ]);

        $this->metrics->increment('queue.failed', [
            'queue' => $queue,
            'error_type' => get_class($e)
        ]);

        if ($job->attempts() >= $job->maxAttempts()) {
            $this->alerts->trigger('job_max_attempts_reached', [
                'queue' => $queue,
                'job' => get_class($job)
            ]);
        }
    }

    protected function recordJobSuccess($job, float $startTime): void
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->metrics->timing('queue.execution_time', $executionTime, [
            'job_type' => get_class($job)
        ]);
    }

    protected function recordJobFailure($job, \Throwable $e, float $startTime): void
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->metrics->timing('queue.failed_execution_time', $executionTime, [
            'job_type' => get_class($job),
            'error_type' => get_class($e)
        ]);
    }
}