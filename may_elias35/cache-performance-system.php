namespace App\Core\Performance;

class CacheManager implements CacheInterface 
{
    private CacheStore $store;
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CacheStore $store,
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, mixed $data, int $ttl = 3600): mixed 
    {
        return $this->security->executeCriticalOperation(new class($key, $data, $ttl, $this->store, $this->metrics) implements CriticalOperation {
            private string $key;
            private mixed $data;
            private int $ttl;
            private CacheStore $store;
            private MetricsCollector $metrics;

            public function __construct(string $key, mixed $data, int $ttl, CacheStore $store, MetricsCollector $metrics) 
            {
                $this->key = $key;
                $this->data = $data;
                $this->ttl = $ttl;
                $this->store = $store;
                $this->metrics = $metrics;
            }

            public function execute(): OperationResult 
            {
                $startTime = microtime(true);
                
                if ($cached = $this->store->get($this->key)) {
                    $this->metrics->recordCacheHit($this->key, microtime(true) - $startTime);
                    return new OperationResult($cached);
                }

                $value = is_callable($this->data) ? ($this->data)() : $this->data;
                
                $this->store->put($this->key, $value, $this->ttl);
                $this->metrics->recordCacheMiss($this->key, microtime(true) - $startTime);
                
                return new OperationResult($value);
            }

            public function getValidationRules(): array 
            {
                return [
                    'key' => 'required|string|max:255',
                    'ttl' => 'required|integer|min:0'
                ];
            }

            public function getData(): array 
            {
                return [
                    'key' => $this->key,
                    'ttl' => $this->ttl
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['cache.write'];
            }

            public function getRateLimitKey(): string 
            {
                return "cache:write:{$this->key}";
            }
        });
    }

    public function forget(string $key): bool 
    {
        return $this->security->executeCriticalOperation(new class($key, $this->store) implements CriticalOperation {
            private string $key;
            private CacheStore $store;

            public function __construct(string $key, CacheStore $store) 
            {
                $this->key = $key;
                $this->store = $store;
            }

            public function execute(): OperationResult 
            {
                $result = $this->store->forget($this->key);
                return new OperationResult($result);
            }

            public function getValidationRules(): array 
            {
                return ['key' => 'required|string|max:255'];
            }

            public function getData(): array 
            {
                return ['key' => $this->key];
            }

            public function getRequiredPermissions(): array 
            {
                return ['cache.delete'];
            }

            public function getRateLimitKey(): string 
            {
                return "cache:delete:{$this->key}";
            }
        });
    }

    public function tags(array $tags): TaggedCache 
    {
        return new TaggedCache($this->store, $tags, $this->security);
    }

    public function flush(): bool 
    {
        return $this->security->executeCriticalOperation(new class($this->store) implements CriticalOperation {
            private CacheStore $store;

            public function __construct(CacheStore $store) 
            {
                $this->store = $store;
            }

            public function execute(): OperationResult 
            {
                $result = $this->store->flush();
                return new OperationResult($result);
            }

            public function getValidationRules(): array 
            {
                return [];
            }

            public function getData(): array 
            {
                return [];
            }

            public function getRequiredPermissions(): array 
            {
                return ['cache.flush'];
            }

            public function getRateLimitKey(): string 
            {
                return 'cache:flush';
            }
        });
    }

    public function getMetrics(): array 
    {
        return $this->metrics->getCacheMetrics();
    }

    public function optimizeCache(): void 
    {
        $this->security->executeCriticalOperation(new class($this->store, $this->metrics) implements CriticalOperation {
            private CacheStore $store;
            private MetricsCollector $metrics;

            public function __construct(CacheStore $store, MetricsCollector $metrics) 
            {
                $this->store = $store;
                $this->metrics = $metrics;
            }

            public function execute(): OperationResult 
            {
                $metrics = $this->metrics->getCacheMetrics();
                
                foreach ($metrics['least_used_keys'] as $key) {
                    $this->store->forget($key);
                }

                foreach ($metrics['expired_keys'] as $key) {
                    $this->store->forget($key);
                }

                return new OperationResult(true);
            }

            public function getValidationRules(): array 
            {
                return [];
            }

            public function getData(): array 
            {
                return [];
            }

            public function getRequiredPermissions(): array 
            {
                return ['cache.optimize'];
            }

            public function getRateLimitKey(): string 
            {
                return 'cache:optimize';
            }
        });
    }
}
