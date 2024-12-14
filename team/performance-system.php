<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{CacheManagerInterface, PerformanceInterface};

class PerformanceManager implements PerformanceInterface
{
    private CoreSecurityManager $security;
    private array $metrics = [];
    private array $thresholds = [
        'response_time' => 200,
        'query_time' => 50,
        'memory_limit' => 128 * 1024 * 1024
    ];

    public function monitor(callable $operation, string $context): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            DB::beginTransaction();
            
            $result = $operation();
            
            $this->recordMetrics($context, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'query_count' => DB::getQueryLog()->count()
            ]);

            $this->validatePerformance($context);
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handlePerformanceFailure($e, $context);
            throw $e;
        }
    }

    private function validatePerformance(string $context): void
    {
        if (!isset($this->metrics[$context])) {
            return;
        }

        $metrics = $this->metrics[$context];

        if ($metrics['execution_time'] > $this->thresholds['response_time']) {
            throw new PerformanceException("Response time threshold exceeded");
        }

        if ($metrics['memory_usage'] > $this->thresholds['memory_limit']) {
            throw new PerformanceException("Memory usage threshold exceeded");
        }
    }

    private function recordMetrics(string $context, array $metrics): void
    {
        $this->metrics[$context] = $metrics;
        Cache::put("metrics:$context", $metrics, 3600);
    }
}

class CacheManager implements CacheManagerInterface
{
    private array $stores = ['redis', 'file'];
    private array $tags = [];
    private int $defaultTTL = 3600;

    public function remember(string $key, mixed $data, ?int $ttl = null): mixed
    {
        return Cache::tags($this->tags)->remember(
            $this->generateKey($key),
            $ttl ?? $this->defaultTTL,
            function() use ($data) {
                return is_callable($data) ? $data() : $data;
            }
        );
    }

    public function invalidate(string $key): void
    {
        Cache::tags($this->tags)->forget($this->generateKey($key));
    }

    public function flush(): void
    {
        foreach ($this->stores as $store) {
            Cache::store($store)->flush();
        }
    }

    public function withTags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    private function generateKey(string $key): string
    {
        return hash('sha256', $key . config('app.key'));
    }
}

class QueryOptimizer
{
    private array $queryLog = [];
    private array $optimizationRules = [];

    public function optimize(string $query): string
    {
        $this->logQuery($query);
        
        foreach ($this->optimizationRules as $rule) {
            $query = $rule->apply($query);
        }

        return $query;
    }

    public function analyze(): array
    {
        return [
            'total_queries' => count($this->queryLog),
            'slow_queries' => $this->getSlowQueries(),
            'optimization_suggestions' => $this->generateSuggestions()
        ];
    }

    private function logQuery(string $query): void
    {
        $this->queryLog[] = [
            'query' => $query,
            'time' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
    }

    private function getSlowQueries(): array
    {
        return array_filter($this->queryLog, function($query) {
            return $query['time'] > 50;
        });
    }

    private function generateSuggestions(): array
    {
        $suggestions = [];
        foreach ($this->queryLog as $query) {
            foreach ($this->optimizationRules as $rule) {
                if ($suggestion = $rule->suggest($query['query'])) {
                    $suggestions[] = $suggestion;
                }
            }
        }
        return $suggestions;
    }
}

class ResourceMonitor
{
    private array $limits = [
        'cpu' => 70,
        'memory' => 80,
        'disk' => 90
    ];

    public function checkResources(): array
    {
        $usage = [
            'cpu' => $this->getCPUUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage()
        ];

        foreach ($usage as $resource => $value) {
            if ($value > $this->limits[$resource]) {
                Log::warning("Resource limit exceeded: $resource at $value%");
            }
        }

        return $usage;
    }

    private function getCPUUsage(): float
    {
        return sys_getloadavg()[0] * 100;
    }

    private function getMemoryUsage(): float
    {
        return memory_get_usage(true) / memory_get_peak_usage(true) * 100;
    }

    private function getDiskUsage(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        return ($total - $free) / $total * 100;
    }
}
