namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private $store;
    private SecurityManager $security;
    private ValidatorService $validator;
    private MetricsCollector $metrics;

    public function __construct(
        Repository $store,
        SecurityManager $security,
        ValidatorService $validator,
        MetricsCollector $metrics
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $this->validateKey($key);
        $startTime = microtime(true);

        try {
            if ($cached = $this->get($key)) {
                $this->metrics->recordCacheHit($key);
                return $cached;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            $this->metrics->recordCacheMiss($key);

            return $value;
        } finally {
            $this->metrics->recordOperationTime(
                'cache_operation',
                microtime(true) - $startTime
            );
        }
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheWriteOperation(
                $key,
                $this->prepareForCache($value),
                $ttl,
                $this->store
            ),
            SecurityContext::fromRequest()
        );
    }

    public function get(string $key): mixed
    {
        return $this->security->executeCriticalOperation(
            new CacheReadOperation($key, $this->store),
            SecurityContext::fromRequest()
        );
    }

    public function forget(string $key): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheDeleteOperation($key, $this->store),
            SecurityContext::fromRequest()
        );
    }

    public function tags(array $tags): static
    {
        foreach ($tags as $tag) {
            $this->validator->validateTag($tag);
        }

        $this->store = $this->store->tags($tags);
        return $this;
    }

    public function flush(): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheFlushOperation($this->store),
            SecurityContext::fromRequest()
        );
    }

    private function prepareForCache(mixed $value): mixed
    {
        if (is_object($value) && !($value instanceof Serializable)) {
            throw new CacheException('Value must be serializable');
        }

        if (strlen(serialize($value)) > 1024 * 1024) {
            throw new CacheException('Value exceeds maximum size');
        }

        return $value;
    }

    private function validateKey(string $key): void
    {
        if (!$this->validator->isValidCacheKey($key)) {
            throw new InvalidArgumentException('Invalid cache key format');
        }
    }

    public function getMultiple(array $keys): array
    {
        return $this->security->executeCriticalOperation(
            new CacheMultiReadOperation($keys, $this->store),
            SecurityContext::fromRequest()
        );
    }

    public function setMultiple(array $values, int $ttl): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheMultiWriteOperation(
                $values,
                $ttl,
                $this->store
            ),
            SecurityContext::fromRequest()
        );
    }

    public function forgetMultiple(array $keys): bool
    {
        return $this->security->executeCriticalOperation(
            new CacheMultiDeleteOperation($keys, $this->store),
            SecurityContext::fromRequest()
        );
    }

    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }

    public function lock(string $key, int $seconds = 0): Lock
    {
        return new CacheLock($this, $key, $seconds);
    }

    public function restoreLock(string $key): Lock
    {
        return new CacheLock($this, $key, 0);
    }
}
